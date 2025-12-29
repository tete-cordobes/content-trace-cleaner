<?php
/**
 * Clase para limpiar atributos de rastreo LLM del HTML
 *
 * @package Content_Trace_Cleaner
 */

defined('ABSPATH') || exit;

/**
 * Clase Content_Trace_Cleaner_Cleaner
 */
class Content_Trace_Cleaner_Cleaner {

    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;

    /**
     * Atributos a eliminar
     */
    private $attributes_to_remove = array(
        'data-start',
        'data-end',
        'data-is-last-node',
        'data-is-only-node',
        'data-llm',
        'data-pm-slice',
        'data-llm-id',
        'data-llm-trace',
        'data-original-text',
        'data-source-text',
        'data-highlight',
        'data-entity',
        'data-mention',
        'data-offset-key',
        'data-message-id',
        'data-sender',
        'data-role',
        'data-token-index',
        'data-model',
        'data-render-timestamp',
        'data-update-timestamp',
        'data-confidence',
        'data-temperature',
        'data-seed',
        'data-step',
        'data-lang',
        'data-format',
        'data-annotation',
        'data-reference',
        'data-version',
        'data-error',
        'data-stream-id',
        'data-chunk',
        'data-context-id',
        'data-user-id',
        'data-ui-state',
    );

    /**
     * Patrón para IDs de respuesta de modelo
     */
    private $id_pattern = '/^model-response-message-contentr_/';

    /**
     * Estadísticas de la última limpieza
     */
    private $last_stats = array();

    /**
     * Ubicaciones de cambios
     */
    private $change_locations = array();

    /**
     * Mapa de caracteres Unicode invisibles
     */
    private $invisible_unicode_map = array(
        'Zero Width Space (U+200B)' => '/\x{200B}/u',
        'Zero Width Non-Joiner (U+200C)' => '/\x{200C}/u',
        'Zero Width Joiner (U+200D)' => '/\x{200D}/u',
        'Zero Width No-Break Space / BOM (U+FEFF)' => '/\x{FEFF}/u',
        'Word Joiner (U+2060)' => '/\x{2060}/u',
        'Soft Hyphen (U+00AD)' => '/\x{00AD}/u',
        'Invisible Separator (U+2063)' => '/\x{2063}/u',
        'Invisible Plus (U+2064)' => '/\x{2064}/u',
        'Invisible Times (U+2062)' => '/\x{2062}/u',
        'Left-to-Right Mark (U+200E)' => '/\x{200E}/u',
        'Right-to-Left Mark (U+200F)' => '/\x{200F}/u',
        'Left-to-Right Embedding (U+202A)' => '/\x{202A}/u',
        'Right-to-Left Embedding (U+202B)' => '/\x{202B}/u',
        'Pop Directional Formatting (U+202C)' => '/\x{202C}/u',
        'Left-to-Right Override (U+202D)' => '/\x{202D}/u',
        'Right-to-Left Override (U+202E)' => '/\x{202E}/u',
        'Bidirectional Isolates (U+2066–U+2069)' => '/[\x{2066}-\x{2069}]/u',
        'Mongolian Vowel Separator (U+180E)' => '/\x{180E}/u',
        'Tag Characters (U+E0000–U+E007F)' => '/[\x{E0000}-\x{E007F}]/u',
        'Invisible Ideographic Space (U+3000)' => '/\x{3000}/u',
        'Object Replacement Character (U+FFFC)' => '/\x{FFFC}/u',
        'Variation Selectors (U+FE00–U+FE0F)' => '/[\x{FE00}-\x{FE0F}]/u',
    );

    /**
     * Obtener instancia única
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Constructor vacío - Singleton
    }

    /**
     * Limpiar HTML eliminando atributos de rastreo LLM
     *
     * @param string $html Contenido HTML a limpiar
     * @param array $options Opciones de limpieza
     * @return string HTML limpio
     */
    public function clean_html($html, $options = array()) {
        if (empty($html)) {
            return $html;
        }

        $default_options = array(
            'clean_attributes' => true,
            'clean_unicode' => true,
            'clean_content_references' => true,
            'clean_utm_parameters' => true,
            'track_locations' => true
        );
        $options = wp_parse_args($options, $default_options);

        $this->last_stats = array();
        $this->change_locations = array();

        $html = $this->decode_unicode_sequences($html);

        $gutenberg_data = $this->extract_gutenberg_blocks($html);
        $html = $gutenberg_data['html'];

        $original_html = $html;

        if ($options['clean_attributes']) {
            if (class_exists('DOMDocument')) {
                $cleaned_html = $this->clean_with_dom($html, $options);
            } else {
                $cleaned_html = $this->clean_with_regex($html, $options);
            }
        } else {
            $cleaned_html = $html;
        }

        if (!empty($options['clean_unicode'])) {
            $cleaned_html = $this->remove_invisible_unicode($cleaned_html, $options);
        }

        if ($options['clean_content_references']) {
            $cleaned_html = $this->remove_content_references($cleaned_html, $options);
        }

        if ($options['clean_utm_parameters']) {
            $cleaned_html = $this->remove_utm_parameters($cleaned_html, $options);
        }

        if ($options['clean_unicode'] && !empty($gutenberg_data['blocks'])) {
            $cleaned_blocks = array();
            foreach ($gutenberg_data['blocks'] as $block) {
                $cleaned_blocks[] = $this->remove_invisible_unicode($block, $options);
            }
            $gutenberg_data['blocks'] = $cleaned_blocks;
        }

        if ($options['clean_utm_parameters'] && !empty($gutenberg_data['blocks'])) {
            $cleaned_blocks = array();
            foreach ($gutenberg_data['blocks'] as $block) {
                $cleaned_blocks[] = $this->remove_utm_parameters($block, $options);
            }
            $gutenberg_data['blocks'] = $cleaned_blocks;
        }

        $cleaned_html = $this->restore_gutenberg_blocks(
            $cleaned_html,
            $gutenberg_data['blocks'],
            $gutenberg_data['placeholders']
        );

        return $cleaned_html;
    }

    /**
     * Extraer y preservar bloques de Gutenberg
     */
    private function extract_gutenberg_blocks($html) {
        $blocks = array();
        $placeholders = array();
        $counter = 0;

        $pattern = '/<!--\s*(wp:[^\s]+(?:\s+[^>]+)?)\s-->(.*?)<!--\s*(\/wp:[^\s]+)\s-->/s';

        $max_iterations = 10;
        $iteration = 0;

        while ($iteration < $max_iterations) {
            $found = false;
            $html = preg_replace_callback($pattern, function($matches) use (&$blocks, &$placeholders, &$counter, &$found) {
                $opening_block = trim($matches[1]);
                $closing_block = trim($matches[3]);
                $content = $matches[2];

                preg_match('/wp:([^\s]+)/', $opening_block, $opening_match);
                preg_match('/\/wp:([^\s]+)/', $closing_block, $closing_match);

                if (isset($opening_match[1]) && isset($closing_match[1]) && $opening_match[1] === $closing_match[1]) {
                    if (strpos($content, '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_') === false) {
                        $full_block = $matches[0];
                        $placeholder = '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_' . $counter . ']]';

                        $blocks[] = $full_block;
                        $placeholders[] = $placeholder;
                        $counter++;
                        $found = true;
                        return $placeholder;
                    }
                }

                return $matches[0];
            }, $html, -1, $count);

            if ($count === 0 || !$found) {
                break;
            }

            $iteration++;
        }

        // Extraer bloques por clase CSS
        $html = $this->extract_blocks_by_class($html, 'wp-block-rank-math-faq-block', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class($html, 'rank-math-block', $blocks, $placeholders, $counter);

        // Page Builders
        $html = $this->extract_blocks_by_class($html, 'elementor-element', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class($html, 'elementor-widget', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class($html, 'et_pb_section', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class($html, 'et_pb_row', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class_prefix($html, 'brxe-', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class($html, 'vc_row', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class($html, 'fl-row', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class_prefix($html, 'oxy-', $blocks, $placeholders, $counter);
        $html = $this->extract_blocks_by_class_prefix($html, 'fusion-', $blocks, $placeholders, $counter);

        return array(
            'html' => $html,
            'blocks' => $blocks,
            'placeholders' => $placeholders
        );
    }

    /**
     * Extraer bloques por clase CSS
     */
    private function extract_blocks_by_class($html, $class_name, &$blocks, &$placeholders, &$counter) {
        $pattern = '/<div[^>]*class="[^"]*' . preg_quote($class_name, '/') . '[^"]*"[^>]*>/i';

        $offset = 0;
        while (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $match_pos = $matches[0][1];

            $before_match = substr($html, 0, $match_pos);
            if (strpos($before_match, '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_') !== false) {
                $last_placeholder_pos = strrpos($before_match, '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_');
                if ($last_placeholder_pos !== false) {
                    $placeholder_end = strpos($html, ']]', $last_placeholder_pos);
                    if ($placeholder_end !== false && $placeholder_end > $match_pos) {
                        $offset = $match_pos + strlen($matches[0][0]);
                        continue;
                    }
                }
            }

            $full_block = $this->extract_complete_div_block($html, $match_pos);

            if ($full_block && strpos($full_block, '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_') === false) {
                $placeholder = '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_' . $counter . ']]';

                $blocks[] = $full_block;
                $placeholders[] = $placeholder;

                $html = substr_replace($html, $placeholder, $match_pos, strlen($full_block));

                $counter++;
                $offset = $match_pos + strlen($placeholder);
            } else {
                $offset = $match_pos + strlen($matches[0][0]);
            }
        }

        return $html;
    }

    /**
     * Extraer bloques por prefijo de clase CSS
     */
    private function extract_blocks_by_class_prefix($html, $class_prefix, &$blocks, &$placeholders, &$counter) {
        $pattern = '/<div[^>]*class="[^"]*' . preg_quote($class_prefix, '/') . '[^"]*"[^>]*>/i';

        $offset = 0;
        while (preg_match($pattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $match_pos = $matches[0][1];

            $before_match = substr($html, 0, $match_pos);
            if (strpos($before_match, '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_') !== false) {
                $last_placeholder_pos = strrpos($before_match, '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_');
                if ($last_placeholder_pos !== false) {
                    $placeholder_end = strpos($html, ']]', $last_placeholder_pos);
                    if ($placeholder_end !== false && $placeholder_end > $match_pos) {
                        $offset = $match_pos + strlen($matches[0][0]);
                        continue;
                    }
                }
            }

            $full_block = $this->extract_complete_div_block($html, $match_pos);

            if ($full_block && strpos($full_block, '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_') === false) {
                $placeholder = '[[CONTENT_TRACE_CLEANER_GUTENBERG_BLOCK_' . $counter . ']]';

                $blocks[] = $full_block;
                $placeholders[] = $placeholder;

                $html = substr_replace($html, $placeholder, $match_pos, strlen($full_block));

                $counter++;
                $offset = $match_pos + strlen($placeholder);
            } else {
                $offset = $match_pos + strlen($matches[0][0]);
            }
        }

        return $html;
    }

    /**
     * Extraer bloque div completo
     */
    private function extract_complete_div_block($html, $start_pos) {
        $pos = $start_pos;
        $depth = 0;
        $start = $start_pos;

        $open_tag_end = strpos($html, '>', $pos);
        if ($open_tag_end === false) {
            return false;
        }

        $open_tag = substr($html, $pos, $open_tag_end - $pos + 1);
        if (preg_match('/\/\s*>$/', $open_tag)) {
            return $open_tag;
        }

        $depth = 1;
        $pos = $open_tag_end + 1;

        while ($depth > 0 && $pos < strlen($html)) {
            $next_open = strpos($html, '<div', $pos);
            $next_close = strpos($html, '</div>', $pos);

            if ($next_open === false && $next_close === false) {
                return false;
            }

            if ($next_open === false) {
                $depth--;
                if ($depth === 0) {
                    $end_pos = $next_close + 6;
                    return substr($html, $start, $end_pos - $start);
                }
                $pos = $next_close + 6;
            } elseif ($next_close === false) {
                $depth++;
                $pos = strpos($html, '>', $next_open) + 1;
            } else {
                if ($next_open < $next_close) {
                    $depth++;
                    $pos = strpos($html, '>', $next_open) + 1;
                } else {
                    $depth--;
                    if ($depth === 0) {
                        $end_pos = $next_close + 6;
                        return substr($html, $start, $end_pos - $start);
                    }
                    $pos = $next_close + 6;
                }
            }
        }

        return false;
    }

    /**
     * Restaurar bloques de Gutenberg
     */
    private function restore_gutenberg_blocks($html, $blocks, $placeholders) {
        if (empty($blocks) || empty($placeholders)) {
            return $html;
        }

        for ($i = count($placeholders) - 1; $i >= 0; $i--) {
            if (isset($placeholders[$i]) && isset($blocks[$i])) {
                $placeholder = $placeholders[$i];
                $placeholder_escaped = htmlspecialchars($placeholder, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $html = str_replace($placeholder_escaped, $blocks[$i], $html);
                $html = str_replace($placeholder, $blocks[$i], $html);
            }
        }
        return $html;
    }

    /**
     * Decodificar secuencias Unicode
     */
    private function decode_unicode_sequences($html) {
        $html = preg_replace_callback('/u([0-9a-fA-F]{4})/u', function($matches) {
            return $this->decode_unicode_char($matches[1]);
        }, $html);

        $html = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/u', function($matches) {
            return $this->decode_unicode_char($matches[1]);
        }, $html);

        $html = preg_replace_callback('/&#x([0-9a-fA-F]{1,6});/iu', function($matches) {
            $code = intval($matches[1], 16);
            if (!$this->is_invisible_unicode_char($code)) {
                return $this->decode_unicode_char($matches[1], 16);
            }
            return $matches[0];
        }, $html);

        return $html;
    }

    /**
     * Decodificar carácter Unicode
     */
    private function decode_unicode_char($hex_code, $base = 16) {
        $code = intval($hex_code, $base);

        if ($this->is_invisible_unicode_char($code)) {
            return '';
        }

        if ($code >= 32 && $code <= 126) {
            return chr($code);
        } elseif ($code > 126 && $code <= 0x10FFFF) {
            if (function_exists('mb_chr')) {
                return mb_chr($code, 'UTF-8');
            } else {
                return '&#x' . str_pad(dechex($code), 4, '0', STR_PAD_LEFT) . ';';
            }
        }

        return '';
    }

    /**
     * Verificar si es carácter Unicode invisible
     */
    private function is_invisible_unicode_char($code) {
        $invisible_codes = array(
            0x200B, 0x200C, 0x200D, 0xFEFF, 0x2060, 0x00AD,
            0x2063, 0x2064, 0x2062, 0x200E, 0x200F, 0x202A,
            0x202B, 0x202C, 0x202D, 0x202E, 0x180E, 0x3000, 0xFFFC,
        );

        if (($code >= 0x2066 && $code <= 0x2069) ||
            ($code >= 0xE0000 && $code <= 0xE007F) ||
            ($code >= 0xFE00 && $code <= 0xFE0F)) {
            return true;
        }

        return in_array($code, $invisible_codes);
    }

    /**
     * Limpiar usando DOMDocument
     */
    private function clean_with_dom($html, $options = array()) {
        $dom = new DOMDocument();

        libxml_use_internal_errors(true);

        $html_encoded = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        $wrapper = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="content-trace-cleaner-wrapper">' . $html_encoded . '</div></body></html>';

        @$dom->loadHTML($wrapper);

        libxml_clear_errors();

        $wrapper_element = $dom->getElementById('content-trace-cleaner-wrapper');

        if (!$wrapper_element) {
            return $this->clean_with_regex($html);
        }

        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*');

        foreach ($elements as $element) {
            $this->clean_element($element, $html, $options);
        }

        $cleaned_html = $dom->saveHTML($wrapper_element);

        $cleaned_html = preg_replace('/^<div[^>]*id="content-trace-cleaner-wrapper"[^>]*>/', '', $cleaned_html);
        $cleaned_html = preg_replace('/<\/div>$/', '', $cleaned_html);

        $cleaned_html = html_entity_decode($cleaned_html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $cleaned_html;
    }

    /**
     * Limpiar elemento DOM
     */
    private function clean_element($element, $original_html = '', $options = array()) {
        if (!$element->hasAttributes()) {
            return;
        }

        $location = null;
        if (!empty($options['track_locations']) && !empty($original_html)) {
            $location = $this->get_element_location($element, $original_html);
        }

        foreach ($this->get_attributes_to_remove() as $attr) {
            if ($element->hasAttribute($attr)) {
                $element->removeAttribute($attr);
                $this->increment_stat($attr);

                if ($location) {
                    $this->record_change_location('attribute', $attr, $location);
                }
            }
        }

        if ($element->hasAttribute('id')) {
            $id_value = $element->getAttribute('id');
            if (preg_match($this->id_pattern, $id_value)) {
                $element->removeAttribute('id');
                $this->increment_stat('id(model-response-message-contentr_*)');

                if ($location) {
                    $this->record_change_location('attribute', 'id(model-response-message-contentr_*)', $location);
                }
            }
        }
    }

    /**
     * Limpiar usando regex
     */
    private function clean_with_regex($html, $options = array()) {
        $cleaned = $html;

        foreach ($this->get_attributes_to_remove() as $attr) {
            $pattern = '/\s+' . preg_quote($attr, '/') . '(?:\s*=\s*["\'][^"\']*["\'])?/i';
            $count = 0;
            $cleaned = preg_replace($pattern, '', $cleaned, -1, $count);
            if ($count > 0) {
                $this->increment_stat($attr, $count);

                if (!empty($options['track_locations'])) {
                    $this->record_change_location('attribute', $attr, array(
                        'block_type' => 'HTML Element',
                        'block_name' => null,
                        'class' => null
                    ));
                }
            }
        }

        $pattern = '/\s+id\s*=\s*["\']model-response-message-contentr_[^"\']*["\']/i';
        $count = 0;
        $cleaned = preg_replace($pattern, '', $cleaned, -1, $count);
        if ($count > 0) {
            $this->increment_stat('id(model-response-message-contentr_*)', $count);

            if (!empty($options['track_locations'])) {
                $this->record_change_location('attribute', 'id(model-response-message-contentr_*)', array(
                    'block_type' => 'HTML Element',
                    'block_name' => null,
                    'class' => null
                ));
            }
        }

        return $cleaned;
    }

    /**
     * Eliminar caracteres Unicode invisibles
     */
    private function remove_invisible_unicode($html, $options = array()) {
        $map = apply_filters('content_trace_cleaner_unicode_map', $this->invisible_unicode_map);
        if (empty($map) || !is_array($map)) {
            return $html;
        }

        $total_removed = 0;
        foreach ($map as $label => $pattern) {
            $count = 0;
            $html = preg_replace($pattern, '', $html, -1, $count);
            if ($count > 0) {
                $total_removed += $count;
                $this->increment_stat('unicode: ' . $label, $count);

                if (!empty($options['track_locations'])) {
                    $this->record_change_location('unicode', $label, array(
                        'block_type' => 'Text Content',
                        'block_name' => null,
                        'class' => null
                    ));
                }
            }
        }

        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $html = str_replace(
            array('&lt;', '&gt;', '&amp;', '&quot;', '&#039;'),
            array('<', '>', '&', '"', "'"),
            $html
        );

        return $html;
    }

    /**
     * Eliminar referencias de contenido LLM
     */
    private function remove_content_references($html, $options = array()) {
        $patterns = array(
            '/ContentReference\s*\[\s*oaicite\s*[:=]\s*\d+\s*\]\s*\(\s*index\s*=\s*\d+\s*\)/i',
            '/ContentReference\s*\[\s*oaicite\s*[:=]\s*\d+\s*\]\s*\(\s*\)/i',
            '/\[\s*oaicite\s*[:=]\s*\d+\s*\]/i',
        );

        $total_removed = 0;

        foreach ($patterns as $pattern) {
            $count = 0;
            $html = preg_replace($pattern, '', $html, -1, $count);
            if ($count > 0) {
                $total_removed += $count;
            }
        }

        if ($total_removed > 0) {
            $this->increment_stat('content_reference', $total_removed);

            if (!empty($options['track_locations'])) {
                $this->record_change_location('content_reference', 'ContentReference', array(
                    'block_type' => 'Text Content',
                    'block_name' => null,
                    'class' => null
                ));
            }
        }

        return $html;
    }

    /**
     * Eliminar parámetros UTM de enlaces
     */
    private function remove_utm_parameters($html, $options = array()) {
        $total_removed = 0;

        $pattern = '/(<a[^>]+href=["\'])([^"\']+)(["\'])/i';
        $cleaned_html = preg_replace_callback($pattern, function($matches) use (&$total_removed) {
            $before = $matches[1];
            $url = $matches[2];
            $quote = $matches[3];

            $parsed = parse_url($url);
            if ($parsed === false || !isset($parsed['query'])) {
                return $matches[0];
            }

            parse_str($parsed['query'], $params);

            $utm_count = 0;
            foreach ($params as $key => $value) {
                if (strpos($key, 'utm_') === 0) {
                    $utm_count++;
                    unset($params[$key]);
                }
            }

            if ($utm_count > 0) {
                $total_removed += $utm_count;

                $new_query = !empty($params) ? http_build_query($params) : '';

                $new_url = $parsed['scheme'] . '://';
                if (isset($parsed['user'])) {
                    $new_url .= $parsed['user'];
                    if (isset($parsed['pass'])) {
                        $new_url .= ':' . $parsed['pass'];
                    }
                    $new_url .= '@';
                }
                $new_url .= $parsed['host'];
                if (isset($parsed['port'])) {
                    $new_url .= ':' . $parsed['port'];
                }
                if (isset($parsed['path'])) {
                    $new_url .= $parsed['path'];
                }
                if (!empty($new_query)) {
                    $new_url .= '?' . $new_query;
                }
                if (isset($parsed['fragment'])) {
                    $new_url .= '#' . $parsed['fragment'];
                }

                return $before . $new_url . $quote;
            }

            return $matches[0];
        }, $html);

        $pattern2 = '/(https?:\/\/[^\s<>"\']+ )/i';
        $cleaned_html = preg_replace_callback($pattern2, function($matches) use (&$total_removed) {
            $url = $matches[1];

            $parsed = parse_url($url);
            if ($parsed === false || !isset($parsed['query'])) {
                return $url;
            }

            parse_str($parsed['query'], $params);

            $utm_count = 0;
            foreach ($params as $key => $value) {
                if (strpos($key, 'utm_') === 0) {
                    $utm_count++;
                    unset($params[$key]);
                }
            }

            if ($utm_count > 0) {
                $total_removed += $utm_count;

                $new_query = !empty($params) ? http_build_query($params) : '';

                $new_url = $parsed['scheme'] . '://';
                if (isset($parsed['user'])) {
                    $new_url .= $parsed['user'];
                    if (isset($parsed['pass'])) {
                        $new_url .= ':' . $parsed['pass'];
                    }
                    $new_url .= '@';
                }
                $new_url .= $parsed['host'];
                if (isset($parsed['port'])) {
                    $new_url .= ':' . $parsed['port'];
                }
                if (isset($parsed['path'])) {
                    $new_url .= $parsed['path'];
                }
                if (!empty($new_query)) {
                    $new_url .= '?' . $new_query;
                }
                if (isset($parsed['fragment'])) {
                    $new_url .= '#' . $parsed['fragment'];
                }

                return $new_url;
            }

            return $url;
        }, $cleaned_html);

        if ($total_removed > 0) {
            $this->increment_stat('utm_parameters', $total_removed);

            if (!empty($options['track_locations'])) {
                $this->record_change_location('utm_parameters', 'UTM Parameters', array(
                    'block_type' => 'Link',
                    'block_name' => null,
                    'class' => null
                ));
            }
        }

        return $cleaned_html;
    }

    /**
     * Obtener mapa de Unicode invisible
     */
    public function get_invisible_unicode_map() {
        return apply_filters('content_trace_cleaner_unicode_map', $this->invisible_unicode_map);
    }

    /**
     * Obtener lista de atributos a eliminar
     */
    public function get_attributes_to_remove() {
        $attrs = $this->attributes_to_remove;
        $attrs = apply_filters('content_trace_cleaner_attributes', $attrs);
        return array_values(array_unique(array_filter($attrs)));
    }

    /**
     * Incrementar contador de estadísticas
     */
    private function increment_stat($key, $count = 1) {
        if (!isset($this->last_stats[$key])) {
            $this->last_stats[$key] = 0;
        }
        $this->last_stats[$key] += $count;
    }

    /**
     * Obtener estadísticas de la última limpieza
     */
    public function get_last_stats() {
        return $this->last_stats;
    }

    /**
     * Formatear estadísticas como texto
     */
    public function format_stats($stats) {
        if (empty($stats)) {
            return 'Ningún atributo eliminado';
        }

        $parts = array();
        foreach ($stats as $attr => $count) {
            $parts[] = sprintf('%s: %d eliminado%s', $attr, $count, $count > 1 ? 's' : '');
        }

        return implode('; ', $parts);
    }

    /**
     * Analizar contenido sin limpiarlo
     */
    public function analyze_content($html) {
        $analysis = array(
            'attributes_found' => array(),
            'unicode_found' => array(),
            'content_references_found' => array(),
            'utm_parameters_found' => array(),
            'total_attributes' => 0,
            'total_unicode' => 0,
            'total_content_references' => 0,
            'total_utm_parameters' => 0
        );

        $attributes_to_check = $this->get_attributes_to_remove();
        foreach ($attributes_to_check as $attr) {
            $pattern = '/\s+' . preg_quote($attr, '/') . '(?:\s*=\s*["\'][^"\']*["\'])?/i';
            preg_match_all($pattern, $html, $matches);
            $count = count($matches[0]);
            if ($count > 0) {
                $analysis['attributes_found'][$attr] = $count;
                $analysis['total_attributes'] += $count;
            }
        }

        $unicode_map = $this->get_invisible_unicode_map();
        foreach ($unicode_map as $label => $pattern) {
            preg_match_all($pattern, $html, $matches);
            $count = count($matches[0]);
            if ($count > 0) {
                $analysis['unicode_found'][$label] = $count;
                $analysis['total_unicode'] += $count;
            }
        }

        $content_ref_patterns = array(
            '/ContentReference\s*\[\s*oaicite\s*[:=]\s*\d+\s*\]\s*\(\s*index\s*=\s*\d+\s*\)/i',
            '/ContentReference\s*\[\s*oaicite\s*[:=]\s*\d+\s*\]\s*\(\s*\)/i',
            '/\[\s*oaicite\s*[:=]\s*\d+\s*\]/i',
        );

        foreach ($content_ref_patterns as $pattern) {
            preg_match_all($pattern, $html, $matches);
            $count = count($matches[0]);
            if ($count > 0) {
                $analysis['content_references_found']['ContentReference'] =
                    ($analysis['content_references_found']['ContentReference'] ?? 0) + $count;
                $analysis['total_content_references'] += $count;
            }
        }

        $pattern1 = '/(<a[^>]+href=["\'])([^"\']+)(["\'])/i';
        preg_match_all($pattern1, $html, $href_matches);
        $utm_urls = array();
        foreach ($href_matches[2] as $url) {
            $parsed = parse_url($url);
            if ($parsed !== false && isset($parsed['query'])) {
                parse_str($parsed['query'], $params);
                $has_utm = false;
                foreach ($params as $key => $value) {
                    if (strpos($key, 'utm_') === 0) {
                        $has_utm = true;
                        break;
                    }
                }
                if ($has_utm) {
                    $utm_urls[] = $url;
                }
            }
        }

        if (!empty($utm_urls)) {
            $analysis['utm_parameters_found']['UTM Parameters'] = count($utm_urls);
            $analysis['utm_urls_found'] = $utm_urls;
            $analysis['total_utm_parameters'] = count($utm_urls);
        }

        return $analysis;
    }

    /**
     * Obtener ubicación del elemento
     */
    private function get_element_location($node, $original_html) {
        $location = array(
            'tag' => $node->tagName,
            'class' => $node->getAttribute('class'),
            'id' => $node->getAttribute('id'),
            'parent_tag' => $node->parentNode ? $node->parentNode->tagName : null,
            'parent_class' => $node->parentNode ? $node->parentNode->getAttribute('class') : null,
        );

        if (strpos($location['class'], 'wp-block-') !== false) {
            $location['block_type'] = 'Gutenberg Block';
            preg_match('/wp-block-([^\s]+)/', $location['class'], $matches);
            if (!empty($matches[1])) {
                $location['block_name'] = $matches[1];
            }
        } elseif (strpos($location['class'], 'rank-math') !== false) {
            $location['block_type'] = 'RankMath Block';
            if (strpos($location['class'], 'faq') !== false) {
                $location['block_name'] = 'FAQ';
            }
        } elseif ($location['tag'] === 'p') {
            $location['block_type'] = 'Paragraph';
        } elseif (in_array($location['tag'], array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
            $location['block_type'] = 'Heading (' . strtoupper($location['tag']) . ')';
        } elseif ($location['tag'] === 'div') {
            $location['block_type'] = 'Div';
            if (!empty($location['class'])) {
                $location['block_name'] = $location['class'];
            }
        } elseif ($location['tag'] === 'span') {
            $location['block_type'] = 'Span';
        } else {
            $location['block_type'] = ucfirst($location['tag']) . ' Element';
        }

        return $location;
    }

    /**
     * Registrar ubicación de cambios
     */
    private function record_change_location($type, $item, $location) {
        $key = $type . ':' . $item;
        if (!isset($this->change_locations[$key])) {
            $this->change_locations[$key] = array();
        }

        $location_parts = array($location['block_type']);

        if (!empty($location['block_name'])) {
            $location_parts[] = '(' . $location['block_name'] . ')';
        }

        if (!empty($location['class']) && strpos($location['block_type'], $location['class']) === false) {
            $class_display = strlen($location['class']) > 50 ? substr($location['class'], 0, 50) . '...' : $location['class'];
            $location_parts[] = 'class: ' . $class_display;
        }

        $location_key = implode(' ', $location_parts);

        if (!isset($this->change_locations[$key][$location_key])) {
            $this->change_locations[$key][$location_key] = 0;
        }
        $this->change_locations[$key][$location_key]++;
    }

    /**
     * Obtener ubicaciones de cambios
     */
    public function get_change_locations() {
        return $this->change_locations;
    }
}
