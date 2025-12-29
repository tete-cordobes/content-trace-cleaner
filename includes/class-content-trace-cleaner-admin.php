<?php
/**
 * Clase para manejar la interfaz de administración
 *
 * @package Content_Trace_Cleaner
 */

defined('ABSPATH') || exit;

/**
 * Clase Content_Trace_Cleaner_Admin
 */
class Content_Trace_Cleaner_Admin {

    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;

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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Registrar handlers AJAX
        add_action('wp_ajax_content_trace_cleaner_get_total', array($this, 'ajax_get_total_posts'));
        add_action('wp_ajax_content_trace_cleaner_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_content_trace_cleaner_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_content_trace_cleaner_log_error', array($this, 'ajax_log_error'));
        add_action('wp_ajax_content_trace_cleaner_analyze_content', array($this, 'ajax_analyze_content'));
        add_action('wp_ajax_content_trace_cleaner_analyze_all_posts', array($this, 'ajax_analyze_all_posts'));
    }

    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Content Trace Cleaner', 'content-trace-cleaner'),
            __('Content Trace Cleaner', 'content-trace-cleaner'),
            'manage_options',
            'content-trace-cleaner',
            array($this, 'render_admin_page'),
            'dashicons-admin-tools',
            30
        );

        add_submenu_page(
            'content-trace-cleaner',
            __('Configuración', 'content-trace-cleaner'),
            __('Configuración', 'content-trace-cleaner'),
            'manage_options',
            'content-trace-cleaner',
            array($this, 'render_admin_page')
        );

        add_submenu_page(
            'content-trace-cleaner',
            __('Depuración', 'content-trace-cleaner'),
            __('Depuración', 'content-trace-cleaner'),
            'manage_options',
            'content-trace-cleaner-debug',
            array($this, 'render_debug_page')
        );
    }

    /**
     * Manejar envíos de formularios
     */
    public function handle_form_submissions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['content_trace_cleaner_save_settings']) && check_admin_referer('content_trace_cleaner_settings')) {
            $auto_clean = isset($_POST['content_trace_cleaner_auto_clean']) ? true : false;
            update_option('content_trace_cleaner_auto_clean', $auto_clean);

            $disable_cache = isset($_POST['content_trace_cleaner_disable_cache']) ? true : false;
            update_option('content_trace_cleaner_disable_cache', $disable_cache);

            $selected_bots = isset($_POST['content_trace_cleaner_selected_bots']) ?
                array_map('sanitize_text_field', $_POST['content_trace_cleaner_selected_bots']) : array();
            update_option('content_trace_cleaner_selected_bots', $selected_bots);

            $custom_bots = isset($_POST['content_trace_cleaner_custom_bots']) ?
                sanitize_textarea_field($_POST['content_trace_cleaner_custom_bots']) : '';
            update_option('content_trace_cleaner_custom_bots', $custom_bots);

            $batch_size = isset($_POST['content_trace_cleaner_batch_size']) ? absint($_POST['content_trace_cleaner_batch_size']) : 10;
            if ($batch_size < 1) $batch_size = 1;
            if ($batch_size > 100) $batch_size = 100;
            update_option('content_trace_cleaner_batch_size', $batch_size);

            update_option('content_trace_cleaner_clean_attributes', isset($_POST['content_trace_cleaner_clean_attributes']));
            update_option('content_trace_cleaner_clean_unicode', isset($_POST['content_trace_cleaner_clean_unicode']));
            update_option('content_trace_cleaner_clean_content_references', isset($_POST['content_trace_cleaner_clean_content_references']));
            update_option('content_trace_cleaner_clean_utm_parameters', isset($_POST['content_trace_cleaner_clean_utm_parameters']));

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                     esc_html__('Configuración guardada correctamente.', 'content-trace-cleaner') .
                     '</p></div>';
            });
        }

        if (isset($_POST['content_trace_cleaner_clear_log']) && check_admin_referer('content_trace_cleaner_clear_log')) {
            $logger = Content_Trace_Cleaner_Logger::get_instance();
            $logger->clear_log();
            $logger->clear_file_log();

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                     esc_html__('Log vaciado correctamente.', 'content-trace-cleaner') .
                     '</p></div>';
            });
        }

        if (isset($_GET['content_trace_cleaner_download_log']) && check_admin_referer('content_trace_cleaner_download_log')) {
            $logger = Content_Trace_Cleaner_Logger::get_instance();
            $log_content = $logger->get_file_log_content(0);

            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="content-trace-cleaner-' . date('Y-m-d') . '.log"');
            echo $log_content;
            exit;
        }

        if (isset($_GET['content_trace_cleaner_download_debug_log']) && check_admin_referer('content_trace_cleaner_download_debug_log')) {
            $error_logs = $this->get_error_logs();
            $debug_logs = $this->get_debug_logs();

            $content = "=== LOG DE DEPURACIÓN CONTENT TRACE CLEANER ===\n";
            $content .= "Generado: " . current_time('mysql') . "\n\n";

            $content .= "=== ERRORES ===\n";
            if (!empty($error_logs)) {
                foreach ($error_logs as $log) {
                    $content .= "[" . $log['datetime'] . "] " . $log['message'] . "\n";
                    if (!empty($log['context'])) {
                        $content .= "  Contexto: " . $log['context'] . "\n";
                    }
                    $content .= "\n";
                }
            } else {
                $content .= "No hay errores registrados.\n\n";
            }

            $content .= "\n=== LOGS DE DEPURACIÓN ===\n";
            if (!empty($debug_logs)) {
                foreach ($debug_logs as $log) {
                    $content .= "[" . $log['datetime'] . "] " . $log['message'] . "\n";
                    if (!empty($log['data'])) {
                        $content .= print_r($log['data'], true) . "\n";
                    }
                    $content .= "\n";
                }
            } else {
                $content .= "No hay logs de depuración.\n";
            }

            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="content-trace-cleaner-debug-' . date('Y-m-d') . '.log"');
            echo $content;
            exit;
        }
    }

    /**
     * Encolar scripts y estilos
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'content-trace-cleaner_page_content-trace-cleaner' &&
            $hook !== 'content-trace-cleaner_page_content-trace-cleaner-debug' &&
            $hook !== 'toplevel_page_content-trace-cleaner') {
            return;
        }

        wp_enqueue_script('jquery');
    }

    /**
     * AJAX: Obtener total de posts a procesar
     */
    public function ajax_get_total_posts() {
        try {
            check_ajax_referer('content_trace_cleaner_ajax', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Sin permisos.', 'content-trace-cleaner')));
            }

            global $wpdb;
            $post_ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ('post', 'page')
                AND post_status = 'publish'
                ORDER BY ID ASC"
            );

            $total = count($post_ids);

            $clean_options_used = array(
                'clean_attributes' => get_option('content_trace_cleaner_clean_attributes', false) ? 1 : 0,
                'clean_unicode' => get_option('content_trace_cleaner_clean_unicode', false) ? 1 : 0,
                'clean_content_references' => get_option('content_trace_cleaner_clean_content_references', true) ? 1 : 0,
                'clean_utm_parameters' => get_option('content_trace_cleaner_clean_utm_parameters', true) ? 1 : 0,
            );

            if (isset($_POST['selected_clean_types'])) {
                $selected = json_decode(stripslashes($_POST['selected_clean_types']), true);
                if ($selected !== null && is_array($selected)) {
                    $clean_options_used['clean_attributes'] = !empty($selected['attributes']) ? 1 : 0;
                    $clean_options_used['clean_unicode'] = !empty($selected['unicode']) ? 1 : 0;
                    $clean_options_used['clean_content_references'] = !empty($selected['content_references']) ? 1 : 0;
                    $clean_options_used['clean_utm_parameters'] = !empty($selected['utm_parameters']) ? 1 : 0;
                }
            }

            $process_id = 'content_trace_clean_' . time();
            $transient_key = 'content_trace_cleaner_process_' . $process_id;

            $process_data_light = array(
                'total' => $total,
                'processed' => 0,
                'modified' => 0,
                'stats' => array(),
                'started' => current_time('mysql'),
                'clean_options_used' => $clean_options_used,
            );

            $transient_option_key = '_transient_' . $transient_key;
            $transient_timeout_key = '_transient_timeout_' . $transient_key;
            $expiration = time() + 7200;

            $serialized_data = maybe_serialize($process_data_light);

            delete_transient($transient_key);

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                VALUES (%s, %s, 'no')
                ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                $transient_option_key,
                $serialized_data
            ));

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                VALUES (%s, %d, 'no')
                ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                $transient_timeout_key,
                $expiration
            ));

            wp_cache_delete($transient_key, 'transient');
            wp_cache_delete('alloptions', 'options');

            $this->log_debug('Proceso iniciado', array(
                'total_posts' => $total,
                'process_id' => $process_id,
                'first_10_ids' => array_slice($post_ids, 0, 10)
            ));

            wp_send_json_success(array(
                'total' => $total,
                'process_id' => $process_id,
            ));
        } catch (Exception $e) {
            $this->log_error('Excepción en ajax_get_total_posts', $e->getMessage() . ' - ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => __('Error al obtener total de posts: ', 'content-trace-cleaner') . $e->getMessage()));
        }
    }

    /**
     * AJAX: Procesar un lote de posts
     */
    public function ajax_process_batch() {
        check_ajax_referer('content_trace_cleaner_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Sin permisos.', 'content-trace-cleaner')));
        }

        try {
            $process_id = isset($_POST['process_id']) ? sanitize_text_field($_POST['process_id']) : '';
            $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
            $batch_size = get_option('content_trace_cleaner_batch_size', 10);

            if ($offset === 0) {
                $this->log_debug('Información del sistema al iniciar', array(
                    'active_plugins' => $this->get_active_plugins_info(),
                    'hooks_on_save_post' => $this->get_hooks_on_save_post(),
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time')
                ));
            }

            $this->log_debug('Lote iniciado', array(
                'process_id' => $process_id,
                'offset' => $offset,
                'batch_size' => $batch_size,
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                'time_limit' => ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit')
            ));

            if (empty($process_id)) {
                $this->log_error('ID de proceso inválido', 'ajax_process_batch');
                wp_send_json_error(array('message' => __('ID de proceso inválido.', 'content-trace-cleaner')));
            }

            $transient_key = 'content_trace_cleaner_process_' . $process_id;

            wp_cache_delete($transient_key, 'transient');
            wp_cache_delete('alloptions', 'options');

            global $wpdb;
            $transient_option_key_get = '_transient_' . $transient_key;
            $transient_timeout_key_get = '_transient_timeout_' . $transient_key;

            $transient_timeout_value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $transient_timeout_key_get));
            $transient_value_raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $transient_option_key_get));

            if ($transient_timeout_value && intval($transient_timeout_value) > time()) {
                $process_state = maybe_unserialize($transient_value_raw);
            } else {
                $process_state = false;
            }

            if (!$process_state) {
                $this->log_error('Estado del proceso no encontrado', "Process ID: {$process_id}");
                wp_send_json_error(array('message' => __('Estado del proceso no encontrado.', 'content-trace-cleaner')));
            }

            Content_Trace_Cleaner_Cache::disable_cache_for_cleaning();

            @set_time_limit(120);
            @ini_set('memory_limit', '256M');

            $cleaner = Content_Trace_Cleaner_Cleaner::get_instance();
            $logger = Content_Trace_Cleaner_Logger::get_instance();

            $all_post_ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type IN ('post', 'page')
                AND post_status = 'publish'
                ORDER BY ID ASC"
            );
            $processed_count = $process_state['processed'];
            $remaining_ids = array_slice($all_post_ids, $processed_count, $batch_size);

            if (empty($remaining_ids)) {
                $this->log_debug('No hay más posts para procesar', array(
                    'processed' => $process_state['processed'],
                    'total' => $process_state['total']
                ));

                $process_state['processed'] = $process_state['total'];
                $transient_key_final = 'content_trace_cleaner_process_' . $process_id;
                $transient_option_key_final = '_transient_' . $transient_key_final;
                $transient_timeout_key_final = '_transient_timeout_' . $transient_key_final;
                $expiration_final = time() + 7200;
                $serialized_data_final = maybe_serialize($process_state);

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                    VALUES (%s, %s, 'no')
                    ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                    $transient_option_key_final,
                    $serialized_data_final
                ));

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                    VALUES (%s, %d, 'no')
                    ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                    $transient_timeout_key_final,
                    $expiration_final
                ));

                wp_cache_delete($transient_key_final, 'transient');
                wp_cache_delete('alloptions', 'options');

                if ($process_state['processed'] >= $process_state['total']) {
                    Content_Trace_Cleaner_Cache::clear_all_cache();

                    $this->log_debug('Proceso completado', array(
                        'total' => $process_state['total'],
                        'processed' => $process_state['processed'],
                        'modified' => $process_state['modified']
                    ));
                }

                wp_send_json_success(array(
                    'processed' => 0,
                    'modified' => 0,
                    'total_processed' => $process_state['processed'],
                    'total_modified' => $process_state['modified'],
                    'is_complete' => $process_state['processed'] >= $process_state['total'],
                ));
            }

            $query = new WP_Query(array(
                'post_type' => array('post', 'page'),
                'post_status' => 'publish',
                'post__in' => $remaining_ids,
                'posts_per_page' => $batch_size,
                'fields' => 'ids',
                'orderby' => 'post__in',
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));

            $posts = $query->posts;

            $this->log_debug('Posts obtenidos', array(
                'count' => count($posts),
                'post_ids' => $posts,
                'offset' => $offset,
                'processed_count' => $processed_count,
                'remaining_total' => count($all_post_ids) - $processed_count
            ));

            $batch_modified = 0;
            $batch_stats = array();
            $batch_start_time = microtime(true);

            foreach ($posts as $post_id) {
                try {
                    $post_start_time = microtime(true);

                    $post = get_post($post_id);
                    if (!$post) {
                        $this->log_debug('Post no encontrado', array('post_id' => $post_id));
                        continue;
                    }

                    $original_content = $post->post_content;

                    $clean_options = array(
                        'clean_attributes' => get_option('content_trace_cleaner_clean_attributes', false),
                        'clean_unicode' => get_option('content_trace_cleaner_clean_unicode', false),
                        'clean_content_references' => get_option('content_trace_cleaner_clean_content_references', true),
                        'clean_utm_parameters' => get_option('content_trace_cleaner_clean_utm_parameters', true),
                        'track_locations' => true
                    );

                    if (isset($process_state['clean_options_used']) && is_array($process_state['clean_options_used'])) {
                        $clean_options['clean_attributes'] = !empty($process_state['clean_options_used']['clean_attributes']);
                        $clean_options['clean_unicode'] = !empty($process_state['clean_options_used']['clean_unicode']);
                        $clean_options['clean_content_references'] = !empty($process_state['clean_options_used']['clean_content_references']);
                        $clean_options['clean_utm_parameters'] = !empty($process_state['clean_options_used']['clean_utm_parameters']);
                    } elseif (isset($_POST['selected_clean_types'])) {
                        $selected = json_decode(stripslashes($_POST['selected_clean_types']), true);
                        if ($selected !== null && is_array($selected)) {
                            $clean_options['clean_attributes'] = !empty($selected['attributes']);
                            $clean_options['clean_unicode'] = !empty($selected['unicode']);
                            $clean_options['clean_content_references'] = !empty($selected['content_references']);
                            $clean_options['clean_utm_parameters'] = !empty($selected['utm_parameters']);
                        }
                    }

                    $cleaned_content = $cleaner->clean_html($original_content, $clean_options);

                    if ($cleaned_content !== $original_content) {
                        $stats = $cleaner->get_last_stats();
                        $has_unicode = preg_match_all('/\x{200B}/u', $original_content) > 0;
                        $has_utm = preg_match_all('/[?&]utm_[^=&\s"\']+/i', $original_content) > 0;

                        $this->log_debug('Contenido modificado durante limpieza', array(
                            'post_id' => $post_id,
                            'post_title' => $post->post_title,
                            'options' => $clean_options,
                            'original_length' => strlen($original_content),
                            'cleaned_length' => strlen($cleaned_content),
                            'unicode_detected' => $has_unicode,
                            'unicode_before' => preg_match_all('/\x{200B}/u', $original_content),
                            'unicode_after' => preg_match_all('/\x{200B}/u', $cleaned_content),
                            'utm_detected' => $has_utm,
                            'utm_before' => preg_match_all('/[?&]utm_[^=&\s"\']+/i', $original_content),
                            'utm_after' => preg_match_all('/[?&]utm_[^=&\s"\']+/i', $cleaned_content),
                            'stats' => $stats,
                            'has_selected_clean_types' => isset($_POST['selected_clean_types'])
                        ));
                    } elseif (!empty($clean_options['clean_unicode']) || !empty($clean_options['clean_utm_parameters'])) {
                        $has_unicode = preg_match_all('/\x{200B}/u', $original_content) > 0;
                        $has_utm = preg_match_all('/[?&]utm_[^=&\s"\']+/i', $original_content) > 0;

                        if ($has_unicode || $has_utm) {
                            $this->log_debug('Limpieza activada pero sin cambios detectados', array(
                                'post_id' => $post_id,
                                'post_title' => $post->post_title,
                                'options' => $clean_options,
                                'unicode_detected' => $has_unicode,
                                'unicode_count' => preg_match_all('/\x{200B}/u', $original_content),
                                'utm_detected' => $has_utm,
                                'utm_count' => preg_match_all('/[?&]utm_[^=&\s"\']+/i', $original_content),
                                'note' => 'Puede indicar que los elementos están dentro de bloques de page builders'
                            ));
                        }
                    }

                    if ($cleaned_content !== $original_content) {
                        $update_start_time = microtime(true);

                        $update_result = $this->update_post_without_hooks($post_id, $cleaned_content);

                        $update_time = microtime(true) - $update_start_time;

                        if ($update_time > 2) {
                            $this->log_debug('Post tardó mucho en actualizar', array(
                                'post_id' => $post_id,
                                'post_title' => $post->post_title,
                                'update_time' => round($update_time, 2) . ' segundos',
                                'warning' => 'Posible interferencia de plugin'
                            ));
                        }

                        if ($update_result === false) {
                            $this->log_error('Error al actualizar post en la base de datos', "Post ID: {$post_id}, Título: {$post->post_title}");
                            continue;
                        }

                        Content_Trace_Cleaner_Cache::clear_post_cache($post_id);

                        $batch_modified++;

                        $stats = $cleaner->get_last_stats();
                        foreach ($stats as $attr => $count) {
                            if (!isset($batch_stats[$attr])) {
                                $batch_stats[$attr] = 0;
                            }
                            $batch_stats[$attr] += $count;
                        }

                        $change_locations = $cleaner->get_change_locations();
                        $logger->log_action('manual', $post_id, $post->post_title, $stats, true, $original_content, $cleaned_content, $change_locations);
                    }

                    $post_total_time = microtime(true) - $post_start_time;

                    if ($post_total_time > 5) {
                        $this->log_error('Post procesado muy lentamente', array(
                            'post_id' => $post_id,
                            'post_title' => $post->post_title,
                            'time' => round($post_total_time, 2) . ' segundos',
                            'update_time' => isset($update_time) ? round($update_time, 2) . ' segundos' : 'N/A',
                            'active_plugins_count' => count($this->get_active_plugins_info()),
                            'suggestion' => 'Revisa plugins que interceptan save_post'
                        ));
                    }

                    unset($post);
                    unset($original_content);
                    unset($cleaned_content);
                } catch (Exception $e) {
                    $this->log_error($e->getMessage(), "Post ID: {$post_id}");
                    continue;
                }
            }

            $batch_total_time = microtime(true) - $batch_start_time;

            wp_reset_postdata();
            unset($query);
            unset($cleaner);

            $process_state['processed'] += count($posts);
            $process_state['modified'] += $batch_modified;
            foreach ($batch_stats as $attr => $count) {
                if (!isset($process_state['stats'][$attr])) {
                    $process_state['stats'][$attr] = 0;
                }
                $process_state['stats'][$attr] += $count;
            }

            $transient_key_update = 'content_trace_cleaner_process_' . $process_id;
            $transient_option_key_update = '_transient_' . $transient_key_update;
            $transient_timeout_key_update = '_transient_timeout_' . $transient_key_update;
            $expiration_update = time() + 7200;
            $serialized_data_update = maybe_serialize($process_state);

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                VALUES (%s, %s, 'no')
                ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                $transient_option_key_update,
                $serialized_data_update
            ));

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                VALUES (%s, %d, 'no')
                ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
                $transient_timeout_key_update,
                $expiration_update
            ));

            wp_cache_delete($transient_key_update, 'transient');
            wp_cache_delete('alloptions', 'options');

            $this->log_debug('Lote completado', array(
                'processed' => count($posts),
                'modified' => $batch_modified,
                'total_processed' => $process_state['processed'],
                'total_remaining' => $process_state['total'] - $process_state['processed'],
                'progress_percent' => round(($process_state['processed'] / $process_state['total']) * 100, 2) . '%',
                'batch_time' => round($batch_total_time, 2) . ' segundos',
                'avg_time_per_post' => count($posts) > 0 ? round($batch_total_time / count($posts), 2) . ' segundos' : 'N/A',
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
                'is_complete' => $process_state['processed'] >= $process_state['total']
            ));

            if ($process_state['processed'] >= $process_state['total']) {
                Content_Trace_Cleaner_Cache::clear_all_cache();

                $this->log_debug('Proceso completado', array(
                    'total' => $process_state['total'],
                    'processed' => $process_state['processed'],
                    'modified' => $process_state['modified']
                ));
            }

            wp_send_json_success(array(
                'processed' => count($posts),
                'modified' => $batch_modified,
                'total_processed' => $process_state['processed'],
                'total_modified' => $process_state['modified'],
                'is_complete' => $process_state['processed'] >= $process_state['total'],
            ));

        } catch (Exception $e) {
            $this->log_error($e->getMessage(), 'ajax_process_batch');
            wp_send_json_error(array('message' => __('Error durante el procesamiento. Revisa la pestaña de Depuración para más detalles.', 'content-trace-cleaner')));
        }
    }

    /**
     * Registrar un error en el log
     */
    public function log_error($message, $context = '') {
        $logs = get_option('content_trace_cleaner_error_logs', array());

        $logs[] = array(
            'datetime' => current_time('mysql'),
            'message' => $message,
            'context' => $context
        );

        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }

        update_option('content_trace_cleaner_error_logs', $logs);
    }

    /**
     * Registrar información de depuración
     */
    public function log_debug($message, $data = array()) {
        $logs = get_option('content_trace_cleaner_debug_logs', array());

        $logs[] = array(
            'datetime' => current_time('mysql'),
            'message' => $message,
            'data' => $data
        );

        if (count($logs) > 50) {
            $logs = array_slice($logs, -50);
        }

        update_option('content_trace_cleaner_debug_logs', $logs);
    }

    /**
     * Obtener logs de errores
     */
    private function get_error_logs() {
        $logs = get_option('content_trace_cleaner_error_logs', array());
        return array_reverse($logs);
    }

    /**
     * Obtener logs de depuración
     */
    private function get_debug_logs() {
        $logs = get_option('content_trace_cleaner_debug_logs', array());
        return array_reverse($logs);
    }

    /**
     * Limpiar logs de errores
     */
    private function clear_error_logs() {
        delete_option('content_trace_cleaner_error_logs');
    }

    /**
     * Limpiar logs de depuración
     */
    private function clear_debug_logs() {
        delete_option('content_trace_cleaner_debug_logs');
    }

    /**
     * Actualizar post sin ejecutar hooks de save_post
     */
    private function update_post_without_hooks($post_id, $post_content) {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->posts,
            array(
                'post_content' => $post_content,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1)
            ),
            array('ID' => $post_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            clean_post_cache($post_id);
            return $post_id;
        }

        return false;
    }

    /**
     * Convertir valor de memoria a bytes
     */
    private function convert_to_bytes($val) {
        $val = trim($val);
        if (empty($val) || $val === '-1') {
            return PHP_INT_MAX;
        }

        $last = strtolower($val[strlen($val)-1]);
        $val = (int) $val;

        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * Comparar valor actual con recomendado
     */
    private function compare_value($current, $recommended, $type = 'number') {
        if ($type === 'memory') {
            $current_bytes = $this->convert_to_bytes($current);
            $recommended_bytes = $this->convert_to_bytes($recommended);
            if ($current_bytes === PHP_INT_MAX) {
                return 'status-ok';
            }
            return $current_bytes >= $recommended_bytes ? 'status-ok' : 'status-warning';
        } else {
            $current_int = (int)$current;
            $recommended_int = (int)$recommended;
            if ($current_int === 0) {
                return 'status-ok';
            }
            return $current_int >= $recommended_int ? 'status-ok' : 'status-warning';
        }
    }

    /**
     * Obtener información de plugins activos
     */
    private function get_active_plugins_info() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = get_option('active_plugins', array());
        $all_plugins = get_plugins();
        $active_plugins_info = array();

        foreach ($active_plugins as $plugin) {
            if (isset($all_plugins[$plugin])) {
                $active_plugins_info[] = array(
                    'name' => $all_plugins[$plugin]['Name'],
                    'path' => $plugin,
                    'version' => $all_plugins[$plugin]['Version']
                );
            }
        }

        return $active_plugins_info;
    }

    /**
     * Obtener información de callbacks en un hook
     */
    private function get_callback_info($callback) {
        if (is_string($callback)) {
            return $callback;
        } elseif (is_array($callback)) {
            if (is_object($callback[0])) {
                return get_class($callback[0]) . '::' . $callback[1];
            } else {
                return $callback[0] . '::' . $callback[1];
            }
        } elseif (is_object($callback) && ($callback instanceof Closure)) {
            return 'Closure';
        }
        return 'Unknown';
    }

    /**
     * Obtener hooks relacionados con save_post
     */
    private function get_hooks_on_save_post() {
        global $wp_filter;

        $hooks_info = array();

        $related_hooks = array(
            'save_post',
            'wp_insert_post',
            'wp_insert_post_data',
            'pre_post_update',
            'post_updated',
            'edit_post'
        );

        foreach ($related_hooks as $hook_name) {
            if (isset($wp_filter[$hook_name])) {
                $callbacks = array();
                foreach ($wp_filter[$hook_name]->callbacks as $priority => $functions) {
                    foreach ($functions as $function) {
                        $callbacks[] = array(
                            'priority' => $priority,
                            'function' => $this->get_callback_info($function['function'])
                        );
                    }
                }
                if (!empty($callbacks)) {
                    $hooks_info[$hook_name] = $callbacks;
                }
            }
        }

        return $hooks_info;
    }

    /**
     * Renderizar página de depuración
     */
    public function render_debug_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'content-trace-cleaner'));
        }

        if (isset($_POST['content_trace_cleaner_clear_error_log']) && check_admin_referer('content_trace_cleaner_clear_error_log')) {
            $this->clear_error_logs();
            wp_redirect(add_query_arg('content_trace_cleaner_error_cleared', '1', admin_url('admin.php?page=content-trace-cleaner&tab=debug')));
            exit;
        }

        if (isset($_POST['content_trace_cleaner_clear_debug_log']) && check_admin_referer('content_trace_cleaner_clear_debug_log')) {
            $this->clear_debug_logs();
            wp_redirect(add_query_arg('content_trace_cleaner_debug_cleared', '1', admin_url('admin.php?page=content-trace-cleaner&tab=debug')));
            exit;
        }

        if (isset($_GET['content_trace_cleaner_error_cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__('Logs de errores eliminados.', 'content-trace-cleaner') .
                 '</p></div>';
        }

        if (isset($_GET['content_trace_cleaner_debug_cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html__('Logs de depuración eliminados.', 'content-trace-cleaner') .
                 '</p></div>';
        }

        $error_logs = $this->get_error_logs();
        $debug_logs = $this->get_debug_logs();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Depuración y Errores', 'content-trace-cleaner'); ?></h1>

            <div class="content-trace-cleaner-admin">
                <!-- Información del sistema -->
                <div class="content-trace-cleaner-section">
                    <h2><?php echo esc_html__('Información del Sistema', 'content-trace-cleaner'); ?></h2>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th style="width: 200px;"><?php echo esc_html__('Parámetro', 'content-trace-cleaner'); ?></th>
                                <th><?php echo esc_html__('Valor Actual', 'content-trace-cleaner'); ?></th>
                                <th><?php echo esc_html__('Recomendado', 'content-trace-cleaner'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th><?php echo esc_html__('PHP Version:', 'content-trace-cleaner'); ?></th>
                                <td><?php echo esc_html(PHP_VERSION); ?></td>
                                <td><?php echo esc_html__('7.4 o superior', 'content-trace-cleaner'); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('WordPress Version:', 'content-trace-cleaner'); ?></th>
                                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                                <td><?php echo esc_html__('5.0 o superior', 'content-trace-cleaner'); ?></td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('Plugin Version:', 'content-trace-cleaner'); ?></th>
                                <td><?php echo esc_html(defined('CONTENT_TRACE_CLEANER_VERSION') ? CONTENT_TRACE_CLEANER_VERSION : 'N/A'); ?></td>
                                <td>-</td>
                            </tr>
                            <?php
                            $memory_limit = ini_get('memory_limit');
                            $memory_recommended = '256M';
                            ?>
                            <tr>
                                <th><?php echo esc_html__('Memory Limit:', 'content-trace-cleaner'); ?></th>
                                <td><strong><?php echo esc_html($memory_limit); ?></strong></td>
                                <td><?php echo esc_html($memory_recommended); ?></td>
                            </tr>
                            <?php
                            $max_execution_time = ini_get('max_execution_time');
                            $time_recommended = 120;
                            ?>
                            <tr>
                                <th><?php echo esc_html__('Max Execution Time:', 'content-trace-cleaner'); ?></th>
                                <td><strong><?php echo esc_html($max_execution_time); ?> segundos</strong></td>
                                <td><?php echo esc_html($time_recommended); ?> segundos</td>
                            </tr>
                            <tr>
                                <th><?php echo esc_html__('DOMDocument disponible:', 'content-trace-cleaner'); ?></th>
                                <td><strong><?php echo class_exists('DOMDocument') ? esc_html__('Sí', 'content-trace-cleaner') : esc_html__('No', 'content-trace-cleaner'); ?></strong></td>
                                <td><?php echo esc_html__('Sí (recomendado)', 'content-trace-cleaner'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Información de plugins y hooks -->
                <div class="content-trace-cleaner-section">
                    <h2><?php echo esc_html__('Plugins Activos y Hooks', 'content-trace-cleaner'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Información sobre plugins activos y hooks que podrían interferir con el proceso de limpieza.', 'content-trace-cleaner'); ?>
                    </p>

                    <?php
                    $active_plugins = $this->get_active_plugins_info();
                    $hooks_info = $this->get_hooks_on_save_post();
                    ?>

                    <h3><?php echo esc_html__('Plugins Activos', 'content-trace-cleaner'); ?></h3>
                    <p class="description">
                        <?php echo esc_html(sprintf(__('Total: %d plugins activos', 'content-trace-cleaner'), count($active_plugins))); ?>
                    </p>

                    <?php if (!empty($active_plugins)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 60%;"><?php echo esc_html__('Nombre del Plugin', 'content-trace-cleaner'); ?></th>
                                    <th style="width: 20%;"><?php echo esc_html__('Versión', 'content-trace-cleaner'); ?></th>
                                    <th style="width: 20%;"><?php echo esc_html__('Ruta', 'content-trace-cleaner'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($active_plugins as $plugin): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($plugin['name']); ?></strong></td>
                                        <td><?php echo esc_html($plugin['version']); ?></td>
                                        <td><code style="font-size: 11px;"><?php echo esc_html($plugin['path']); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p><?php echo esc_html__('No se pudieron obtener los plugins activos.', 'content-trace-cleaner'); ?></p>
                    <?php endif; ?>

                    <h3 style="margin-top: 30px;"><?php echo esc_html__('Hooks Relacionados con save_post', 'content-trace-cleaner'); ?></h3>
                    <p class="description">
                        <?php echo esc_html__('Estos hooks pueden interceptar el proceso de actualización de posts y causar lentitud o bloqueos.', 'content-trace-cleaner'); ?>
                    </p>

                    <?php if (!empty($hooks_info)): ?>
                        <?php foreach ($hooks_info as $hook_name => $callbacks): ?>
                            <h4 style="margin-top: 20px; margin-bottom: 10px;">
                                <code><?php echo esc_html($hook_name); ?></code>
                                <span style="font-weight: normal; color: #666;">
                                    (<?php echo esc_html(count($callbacks)); ?> <?php echo esc_html__('callback(s)', 'content-trace-cleaner'); ?>)
                                </span>
                            </h4>
                            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;"><?php echo esc_html__('Prioridad', 'content-trace-cleaner'); ?></th>
                                        <th><?php echo esc_html__('Función/Callback', 'content-trace-cleaner'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($callbacks as $callback): ?>
                                        <tr>
                                            <td><code><?php echo esc_html($callback['priority']); ?></code></td>
                                            <td><code><?php echo esc_html($callback['function']); ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p><?php echo esc_html__('No se encontraron hooks relacionados con save_post.', 'content-trace-cleaner'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Logs de errores -->
                <div class="content-trace-cleaner-section">
                    <h2><?php echo esc_html__('Logs de Errores', 'content-trace-cleaner'); ?></h2>

                    <div style="margin-bottom: 20px;">
                        <form method="post" action="" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('content_trace_cleaner_clear_error_log'); ?>
                            <input type="hidden" name="content_trace_cleaner_clear_error_log" value="1">
                            <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Limpiar logs de errores', 'content-trace-cleaner'); ?>">
                        </form>
                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('content_trace_cleaner_download_debug_log', '1'), 'content_trace_cleaner_download_debug_log')); ?>" class="button button-secondary">
                            <?php echo esc_html__('Descargar log de depuración', 'content-trace-cleaner'); ?>
                        </a>
                    </div>

                    <?php if (!empty($error_logs)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 150px;"><?php echo esc_html__('Fecha/Hora', 'content-trace-cleaner'); ?></th>
                                    <th><?php echo esc_html__('Error', 'content-trace-cleaner'); ?></th>
                                    <th style="width: 200px;"><?php echo esc_html__('Contexto', 'content-trace-cleaner'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($error_logs as $log): ?>
                                    <?php if (isset($log['datetime']) && !empty($log['datetime'])): ?>
                                    <tr>
                                        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $log['datetime'])); ?></td>
                                        <td><code><?php echo esc_html(isset($log['message']) ? $log['message'] : ''); ?></code></td>
                                        <td><?php echo esc_html(isset($log['context']) ? $log['context'] : ''); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p><?php echo esc_html__('No hay errores registrados.', 'content-trace-cleaner'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Logs de depuración -->
                <div class="content-trace-cleaner-section">
                    <h2><?php echo esc_html__('Logs de Depuración', 'content-trace-cleaner'); ?></h2>

                    <div style="margin-bottom: 20px;">
                        <form method="post" action="" style="display: inline-block;">
                            <?php wp_nonce_field('content_trace_cleaner_clear_debug_log'); ?>
                            <input type="hidden" name="content_trace_cleaner_clear_debug_log" value="1">
                            <input type="submit" class="button button-secondary" value="<?php echo esc_attr__('Limpiar logs de depuración', 'content-trace-cleaner'); ?>">
                        </form>
                    </div>

                    <?php if (!empty($debug_logs)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 150px;"><?php echo esc_html__('Fecha/Hora', 'content-trace-cleaner'); ?></th>
                                    <th><?php echo esc_html__('Mensaje', 'content-trace-cleaner'); ?></th>
                                    <th style="width: 300px;"><?php echo esc_html__('Datos', 'content-trace-cleaner'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($debug_logs as $log): ?>
                                    <?php if (isset($log['datetime']) && !empty($log['datetime'])): ?>
                                    <tr>
                                        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $log['datetime'])); ?></td>
                                        <td><?php echo esc_html(isset($log['message']) ? $log['message'] : ''); ?></td>
                                        <td><pre style="max-width: 300px; overflow: auto; font-size: 11px; white-space: pre-wrap;"><?php echo esc_html(print_r(isset($log['data']) ? $log['data'] : array(), true)); ?></pre></td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p><?php echo esc_html__('No hay logs de depuración.', 'content-trace-cleaner'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <style>
            .content-trace-cleaner-admin { max-width: 1200px; }
            .content-trace-cleaner-section { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 0; }
            .content-trace-cleaner-section h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        </style>
        <?php
    }

    /**
     * AJAX: Registrar error desde el cliente
     */
    public function ajax_log_error() {
        check_ajax_referer('content_trace_cleaner_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Sin permisos.', 'content-trace-cleaner')));
        }

        $error_data = isset($_POST['error']) ? sanitize_text_field($_POST['error']) : '';
        $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : 'JavaScript AJAX';

        if (!empty($error_data)) {
            $this->log_error('Error AJAX desde cliente: ' . $error_data, $context);
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Obtener progreso del proceso
     */
    public function ajax_get_progress() {
        check_ajax_referer('content_trace_cleaner_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Sin permisos.', 'content-trace-cleaner')));
        }

        $process_id = isset($_POST['process_id']) ? sanitize_text_field($_POST['process_id']) : '';

        if (empty($process_id)) {
            wp_send_json_error(array('message' => __('ID de proceso inválido.', 'content-trace-cleaner')));
        }

        $process_state = get_transient('content_trace_cleaner_process_' . $process_id);

        if (!$process_state) {
            wp_send_json_error(array('message' => __('Estado del proceso no encontrado.', 'content-trace-cleaner')));
        }

        wp_send_json_success($process_state);
    }

    /**
     * Renderizar página de administración
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'content-trace-cleaner'));
        }

        $auto_clean_enabled = get_option('content_trace_cleaner_auto_clean', false);
        $disable_cache = get_option('content_trace_cleaner_disable_cache', false);
        $selected_bots = get_option('content_trace_cleaner_selected_bots', array());
        $custom_bots = get_option('content_trace_cleaner_custom_bots', '');

        $default_bots = array(
            'chatgpt' => 'ChatGPT',
            'claude' => 'Claude',
            'bard' => 'Bard',
            'gpt' => 'GPT',
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'googlebot' => 'Googlebot',
            'bingbot' => 'Bingbot',
            'crawler' => 'Crawler',
            'spider' => 'Spider',
            'bot' => 'Bot',
            'llm' => 'LLM',
            'grok' => 'Grok',
        );

        $logger = Content_Trace_Cleaner_Logger::get_instance();

        $per_page = 50;
        $current_page = isset($_GET['log_page']) ? absint($_GET['log_page']) : 1;
        $offset = ($current_page - 1) * $per_page;
        $total_logs = $logger->get_total_logs_count();
        $total_pages = ceil($total_logs / $per_page);

        $recent_logs = $logger->get_recent_logs($per_page, $offset);
        $scan_result = get_transient('content_trace_cleaner_scan_result');

        if ($scan_result) {
            delete_transient('content_trace_cleaner_scan_result');
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Content Trace Cleaner', 'content-trace-cleaner'); ?></h1>

            <div class="content-trace-cleaner-admin">
                <!-- Configuración -->
                <div class="content-trace-cleaner-section">
                    <h2><?php echo esc_html__('Configuración', 'content-trace-cleaner'); ?></h2>

                    <!-- Bloque explicativo del plugin -->
                    <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
                        <h3 style="margin-top: 0; color: #2271b1;"><?php echo esc_html__('¿Cómo funciona Content Trace Cleaner?', 'content-trace-cleaner'); ?></h3>
                        <p style="margin-bottom: 10px;">
                            <?php echo esc_html__('Content Trace Cleaner elimina automáticamente los atributos de rastreo que las herramientas de inteligencia artificial (ChatGPT, Claude, Gemini, etc.) agregan al contenido cuando se copia y pega desde ellas, incluso cuando se usan sus APIs para generar contenido.', 'content-trace-cleaner'); ?>
                        </p>
                        <p style="margin-bottom: 10px;">
                            <?php echo esc_html__('El plugin detecta y elimina:', 'content-trace-cleaner'); ?>
                        </p>
                        <ul style="margin-left: 20px; margin-bottom: 10px;">
                            <li><?php echo esc_html__('Atributos de rastreo HTML (data-llm, data-start, data-end, etc.)', 'content-trace-cleaner'); ?></li>
                            <li><?php echo esc_html__('Caracteres Unicode invisibles que pueden afectar el SEO', 'content-trace-cleaner'); ?></li>
                            <li><?php echo esc_html__('Referencias de contenido LLM (ContentReference)', 'content-trace-cleaner'); ?></li>
                            <li><?php echo esc_html__('Parámetros UTM de enlaces agregados por LLMs', 'content-trace-cleaner'); ?></li>
                        </ul>
                        <p style="margin-bottom: 10px;">
                            <strong><?php echo esc_html__('Beneficios:', 'content-trace-cleaner'); ?></strong>
                            <?php echo esc_html__('Contenido más limpio, mejor rendimiento, HTML optimizado y sin rastros de herramientas LLM.', 'content-trace-cleaner'); ?>
                        </p>
                        <p style="margin-bottom: 0; padding-top: 10px; border-top: 1px solid #c3c4c7;">
                            <strong><?php echo esc_html__('Consejos:', 'content-trace-cleaner'); ?></strong>
                            <?php echo esc_html__('Recuerda revisar tus posts o páginas después de la limpieza, los caracteres Unicode de control pueden causar fallos en el diseño y estilos de los textos.', 'content-trace-cleaner'); ?>
                        </p>
                    </div>

                    <form method="post" action="">
                        <?php wp_nonce_field('content_trace_cleaner_settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="content_trace_cleaner_auto_clean">
                                        <?php echo esc_html__('Limpieza automática', 'content-trace-cleaner'); ?>
                                    </label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                               name="content_trace_cleaner_auto_clean"
                                               id="content_trace_cleaner_auto_clean"
                                               value="1"
                                               <?php checked($auto_clean_enabled, true); ?>>
                                        <?php echo esc_html__('Activar limpieza automática al guardar entradas/páginas', 'content-trace-cleaner'); ?>
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('Si está activada, el contenido se limpiará automáticamente cada vez que se guarde una entrada o página.', 'content-trace-cleaner'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="content_trace_cleaner_disable_cache">
                                        <?php echo esc_html__('Desactivar caché para bots/LLMs', 'content-trace-cleaner'); ?>
                                    </label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                               name="content_trace_cleaner_disable_cache"
                                               id="content_trace_cleaner_disable_cache"
                                               value="1"
                                               <?php checked($disable_cache, true); ?>>
                                        <?php echo esc_html__('Desactivar caché cuando se detecten bots o LLMs', 'content-trace-cleaner'); ?>
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('Evita que los plugins de caché interfieran cuando bots o herramientas LLM acceden al sitio. Compatible con LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache y NitroPack.', 'content-trace-cleaner'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr id="content-trace-cleaner-bots-config" style="<?php echo $disable_cache ? '' : 'display:none;'; ?>">
                                <th scope="row">
                                    <label><?php echo esc_html__('Bots/LLMs a detectar', 'content-trace-cleaner'); ?></label>
                                </th>
                                <td>
                                    <fieldset>
                                        <div style="margin-bottom:8px;">
                                            <button type="button" id="content-trace-cleaner-select-all-bots" class="button button-secondary">
                                                <?php echo esc_html__('Seleccionar todos', 'content-trace-cleaner'); ?>
                                            </button>
                                            <button type="button" id="content-trace-cleaner-unselect-all-bots" class="button" style="margin-left:8px;">
                                                <?php echo esc_html__('Deseleccionar', 'content-trace-cleaner'); ?>
                                            </button>
                                        </div>
                                        <?php foreach ($default_bots as $bot_key => $bot_label): ?>
                                            <label style="display: block; margin-bottom: 5px;">
                                                <input type="checkbox"
                                                       name="content_trace_cleaner_selected_bots[]"
                                                       value="<?php echo esc_attr($bot_key); ?>"
                                                       <?php checked(in_array($bot_key, $selected_bots), true); ?>>
                                                <?php echo esc_html($bot_label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <p class="description" style="margin-top: 10px;">
                                        <?php echo esc_html__('Selecciona los bots/LLMs que quieres detectar. Si no seleccionas ninguno, se usarán todos por defecto.', 'content-trace-cleaner'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr id="content-trace-cleaner-custom-bots-config" style="<?php echo $disable_cache ? '' : 'display:none;'; ?>">
                                <th scope="row">
                                    <label for="content_trace_cleaner_custom_bots">
                                        <?php echo esc_html__('Bots personalizados', 'content-trace-cleaner'); ?>
                                    </label>
                                </th>
                                <td>
                                    <textarea name="content_trace_cleaner_custom_bots"
                                              id="content_trace_cleaner_custom_bots"
                                              rows="5"
                                              class="large-text code"><?php echo esc_textarea($custom_bots); ?></textarea>
                                    <p class="description">
                                        <?php echo esc_html__('Agrega bots personalizados, uno por línea. Se buscarán en el User-Agent del visitante.', 'content-trace-cleaner'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="content_trace_cleaner_batch_size">
                                        <?php echo esc_html__('Posts por lote', 'content-trace-cleaner'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="number"
                                           name="content_trace_cleaner_batch_size"
                                           id="content_trace_cleaner_batch_size"
                                           value="<?php echo esc_attr(get_option('content_trace_cleaner_batch_size', 10)); ?>"
                                           min="1"
                                           max="100"
                                           step="1"
                                           class="small-text">
                                    <p class="description">
                                        <?php echo esc_html__('Número de posts a procesar por lote. Se recomienda entre 10 y 30 dependiendo del servidor.', 'content-trace-cleaner'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php echo esc_html__('Tipos de limpieza', 'content-trace-cleaner'); ?></label>
                                </th>
                                <td>
                                    <fieldset>
                                        <label style="display: block; margin-bottom: 10px;">
                                            <input type="checkbox"
                                                   name="content_trace_cleaner_clean_attributes"
                                                   id="content_trace_cleaner_clean_attributes"
                                                   value="1"
                                                   <?php checked(get_option('content_trace_cleaner_clean_attributes', false), true); ?>>
                                            <strong><?php echo esc_html__('Limpiar parámetros y atributos de rastreo', 'content-trace-cleaner'); ?></strong>
                                        </label>
                                        <p class="description" style="margin-left: 25px; margin-top: 5px; margin-bottom: 15px;">
                                            <?php echo esc_html__('Elimina atributos como data-llm, data-start, data-end, data-offset-key, etc.', 'content-trace-cleaner'); ?>
                                        </p>
                                        <label style="display: block;">
                                            <input type="checkbox"
                                                   name="content_trace_cleaner_clean_unicode"
                                                   id="content_trace_cleaner_clean_unicode"
                                                   value="1"
                                                   <?php checked(get_option('content_trace_cleaner_clean_unicode', false), true); ?>>
                                            <strong><?php echo esc_html__('Limpiar caracteres Unicode invisibles', 'content-trace-cleaner'); ?></strong>
                                        </label>
                                        <p class="description" style="margin-left: 25px; margin-top: 5px;">
                                            <?php echo esc_html__('Elimina caracteres invisibles como Zero Width Space, Zero Width Non-Joiner, etc.', 'content-trace-cleaner'); ?>
                                        </p>
                                        <label style="display: block; margin-top: 15px;">
                                            <input type="checkbox"
                                                   name="content_trace_cleaner_clean_content_references"
                                                   id="content_trace_cleaner_clean_content_references"
                                                   value="1"
                                                   <?php checked(get_option('content_trace_cleaner_clean_content_references', true), true); ?>>
                                            <strong><?php echo esc_html__('Limpiar referencias de contenido (ContentReference)', 'content-trace-cleaner'); ?></strong>
                                        </label>
                                        <p class="description" style="margin-left: 25px; margin-top: 5px;">
                                            <?php echo esc_html__('Elimina referencias de contenido LLM como ContentReference [oaicite:=0](index=0) y variaciones similares.', 'content-trace-cleaner'); ?>
                                        </p>
                                        <label style="display: block; margin-top: 15px;">
                                            <input type="checkbox"
                                                   name="content_trace_cleaner_clean_utm_parameters"
                                                   id="content_trace_cleaner_clean_utm_parameters"
                                                   value="1"
                                                   <?php checked(get_option('content_trace_cleaner_clean_utm_parameters', true), true); ?>>
                                            <strong><?php echo esc_html__('Limpiar parámetros UTM de enlaces', 'content-trace-cleaner'); ?></strong>
                                        </label>
                                        <p class="description" style="margin-left: 25px; margin-top: 5px;">
                                            <?php echo esc_html__('Elimina parámetros UTM de los enlaces como ?utm_source=chatgpt.com, ?utm_medium=chat, etc.', 'content-trace-cleaner'); ?>
                                        </p>
                                    </fieldset>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit"
                                   name="content_trace_cleaner_save_settings"
                                   class="button button-primary"
                                   value="<?php echo esc_attr__('Guardar configuración', 'content-trace-cleaner'); ?>">
                        </p>
                    </form>
                </div>

                <!-- Limpieza manual -->
                <div class="content-trace-cleaner-section">
                    <h2><?php echo esc_html__('Limpieza manual', 'content-trace-cleaner'); ?></h2>
                    <p>
                        <?php echo esc_html__('Primero se realizará un análisis del contenido para identificar qué elementos se pueden limpiar. Luego podrás seleccionar qué tipos de limpieza aplicar.', 'content-trace-cleaner'); ?>
                    </p>

                    <div id="content-trace-cleaner-analysis" style="display: none; margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h3><?php echo esc_html__('Análisis previo', 'content-trace-cleaner'); ?></h3>
                        <div id="content-trace-cleaner-analysis-content">
                            <p><?php echo esc_html__('Analizando contenido...', 'content-trace-cleaner'); ?></p>
                        </div>

                        <div id="content-trace-cleaner-selection" style="display: none; margin-top: 20px;">
                            <h4><?php echo esc_html__('Selecciona qué limpiar:', 'content-trace-cleaner'); ?></h4>
                            <fieldset style="margin: 15px 0;">
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" id="content-trace-cleaner-select-attributes" value="1">
                                    <strong><?php echo esc_html__('Limpiar parámetros y atributos de rastreo', 'content-trace-cleaner'); ?></strong>
                                    <span id="content-trace-cleaner-attributes-count" style="color: #666; margin-left: 10px;"></span>
                                </label>
                                <label style="display: block;">
                                    <input type="checkbox" id="content-trace-cleaner-select-unicode" value="1">
                                    <strong><?php echo esc_html__('Limpiar caracteres Unicode invisibles', 'content-trace-cleaner'); ?></strong>
                                    <span id="content-trace-cleaner-unicode-count" style="color: #666; margin-left: 10px;"></span>
                                </label>
                                <label style="display: block; margin-top: 10px;">
                                    <input type="checkbox" id="content-trace-cleaner-select-content-references" value="1">
                                    <strong><?php echo esc_html__('Limpiar referencias de contenido (ContentReference)', 'content-trace-cleaner'); ?></strong>
                                    <span id="content-trace-cleaner-content-references-count" style="color: #666; margin-left: 10px;"></span>
                                </label>
                                <label style="display: block; margin-top: 10px;">
                                    <input type="checkbox" id="content-trace-cleaner-select-utm-parameters" value="1">
                                    <strong><?php echo esc_html__('Limpiar parámetros UTM de enlaces', 'content-trace-cleaner'); ?></strong>
                                    <span id="content-trace-cleaner-utm-parameters-count" style="color: #666; margin-left: 10px;"></span>
                                </label>
                            </fieldset>
                            <p>
                                <button type="button" id="content-trace-cleaner-select-all" class="button button-secondary">
                                    <?php echo esc_html__('Seleccionar todo', 'content-trace-cleaner'); ?>
                                </button>
                            </p>
                        </div>
                    </div>

                    <div id="content-trace-cleaner-progress" style="display: none; margin: 20px 0;">
                        <div class="content-trace-cleaner-progress-bar-container">
                            <div class="content-trace-cleaner-progress-bar">
                                <div class="content-trace-cleaner-progress-bar-fill" id="content-trace-cleaner-progress-fill"></div>
                            </div>
                            <div class="content-trace-cleaner-progress-text" id="content-trace-cleaner-progress-text">0 / 0</div>
                        </div>
                        <p id="content-trace-cleaner-status-text" style="margin-top: 10px;">
                            <?php echo esc_html__('Iniciando...', 'content-trace-cleaner'); ?>
                        </p>
                    </div>

                    <div id="content-trace-cleaner-result" style="display: none;"></div>

                    <p class="submit">
                        <button type="button" id="content-trace-cleaner-analyze-btn" class="button button-primary">
                            <?php echo esc_html__('Analizar contenido', 'content-trace-cleaner'); ?>
                        </button>
                        <button type="button" id="content-trace-cleaner-start-btn" class="button button-secondary" style="display: none;">
                            <?php echo esc_html__('Iniciar limpieza', 'content-trace-cleaner'); ?>
                        </button>
                        <button type="button" id="content-trace-cleaner-stop-btn" class="button button-secondary" style="display: none;">
                            <?php echo esc_html__('Detener', 'content-trace-cleaner'); ?>
                        </button>
                    </p>
                </div>

                <!-- Log -->
                <div class="content-trace-cleaner-section">
                    <h2><?php echo esc_html__('Registro de actividad', 'content-trace-cleaner'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Solo se muestran los posts/páginas que tenían atributos de rastreo eliminados.', 'content-trace-cleaner'); ?>
                    </p>
                    <div style="margin-bottom: 20px;">
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('¿Estás seguro de que quieres vaciar el log?', 'content-trace-cleaner')); ?>');" style="display: inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('content_trace_cleaner_clear_log'); ?>
                            <input type="submit" name="content_trace_cleaner_clear_log" class="button button-secondary" value="<?php echo esc_attr__('Vaciar log', 'content-trace-cleaner'); ?>">
                        </form>
                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('content_trace_cleaner_download_log', '1'), 'content_trace_cleaner_download_log')); ?>" class="button button-secondary">
                            <?php echo esc_html__('Descargar archivo de log', 'content-trace-cleaner'); ?>
                        </a>
                        <span style="margin-left: 15px; color: #666;">
                            <?php printf(esc_html__('Total: %d registros', 'content-trace-cleaner'), $total_logs); ?>
                        </span>
                    </div>

                    <?php if (!empty($recent_logs)): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Fecha/Hora', 'content-trace-cleaner'); ?></th>
                                    <th><?php echo esc_html__('Tipo', 'content-trace-cleaner'); ?></th>
                                    <th><?php echo esc_html__('ID Post', 'content-trace-cleaner'); ?></th>
                                    <th><?php echo esc_html__('Título', 'content-trace-cleaner'); ?></th>
                                    <th><?php echo esc_html__('Detalles', 'content-trace-cleaner'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_logs as $log): ?>
                                    <tr>
                                        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $log->datetime)); ?></td>
                                        <td>
                                            <?php
                                            $type_label = ($log->action_type === 'auto') ?
                                                __('Automático', 'content-trace-cleaner') :
                                                __('Manual', 'content-trace-cleaner');
                                            echo esc_html($type_label);
                                            ?>
                                        </td>
                                        <td><?php echo esc_html($log->post_id); ?></td>
                                        <td>
                                            <?php if ($log->post_id): ?>
                                                <a href="<?php echo esc_url(get_edit_post_link($log->post_id)); ?>" target="_blank">
                                                    <?php echo esc_html($log->post_title); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo esc_html($log->post_title); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($log->details); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($total_pages > 1): ?>
                            <div class="content-trace-cleaner-pagination" style="margin-top: 20px;">
                                <?php
                                $base_url = remove_query_arg('log_page');
                                $base_url = add_query_arg('page', 'content-trace-cleaner', $base_url);

                                if ($current_page > 1):
                                    $prev_url = add_query_arg('log_page', $current_page - 1, $base_url);
                                ?>
                                    <a href="<?php echo esc_url($prev_url); ?>" class="button">
                                        <?php echo esc_html__('« Anterior', 'content-trace-cleaner'); ?>
                                    </a>
                                <?php endif; ?>

                                <span style="margin: 0 15px;">
                                    <?php printf(esc_html__('Página %d de %d', 'content-trace-cleaner'), $current_page, $total_pages); ?>
                                </span>

                                <?php
                                if ($current_page < $total_pages):
                                    $next_url = add_query_arg('log_page', $current_page + 1, $base_url);
                                ?>
                                    <a href="<?php echo esc_url($next_url); ?>" class="button">
                                        <?php echo esc_html__('Siguiente »', 'content-trace-cleaner'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p><?php echo esc_html__('No hay registros en el log.', 'content-trace-cleaner'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <style>
            .content-trace-cleaner-admin { max-width: 1200px; }
            .content-trace-cleaner-section { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; margin: 20px 0; }
            .content-trace-cleaner-section h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
            .content-trace-cleaner-progress-bar-container { display: flex; align-items: center; gap: 15px; }
            .content-trace-cleaner-progress-bar { flex: 1; height: 30px; background: #f0f0f1; border-radius: 4px; overflow: hidden; }
            .content-trace-cleaner-progress-bar-fill { height: 100%; background: #2271b1; width: 0%; transition: width 0.3s ease; }
            .content-trace-cleaner-progress-text { min-width: 80px; text-align: right; font-weight: 600; }
        </style>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#content_trace_cleaner_disable_cache').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#content-trace-cleaner-bots-config, #content-trace-cleaner-custom-bots-config').show();
                } else {
                    $('#content-trace-cleaner-bots-config, #content-trace-cleaner-custom-bots-config').hide();
                }
            });

            $('#content-trace-cleaner-select-all-bots').on('click', function() {
                $('input[name="content_trace_cleaner_selected_bots[]"]').prop('checked', true);
            });
            $('#content-trace-cleaner-unselect-all-bots').on('click', function() {
                $('input[name="content_trace_cleaner_selected_bots[]"]').prop('checked', false);
            });

            var processId = null;
            var offset = 0;
            var totalPosts = 0;
            var isProcessing = false;
            var shouldStop = false;

            var ajaxNonce = '<?php echo wp_create_nonce('content_trace_cleaner_ajax'); ?>';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

            var selectedCleanTypes = {
                attributes: false,
                unicode: false,
                content_references: false,
                utm_parameters: false
            };

            $('#content-trace-cleaner-analyze-btn').on('click', function() {
                $(this).prop('disabled', true).text('<?php echo esc_js(__('Analizando...', 'content-trace-cleaner')); ?>');
                $('#content-trace-cleaner-analysis').show();
                $('#content-trace-cleaner-analysis-content').html('<p><?php echo esc_js(__('Analizando todos los posts... Esto puede tardar varios minutos.', 'content-trace-cleaner')); ?></p>');

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'content_trace_cleaner_analyze_all_posts',
                        nonce: ajaxNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            displayAnalysisResults(response.data);
                        } else {
                            $('#content-trace-cleaner-analysis-content').html('<p style="color: red;">Error: ' + (response.data.message || 'Error desconocido') + '</p>');
                            $('#content-trace-cleaner-analyze-btn').prop('disabled', false).text('<?php echo esc_js(__('Analizar contenido', 'content-trace-cleaner')); ?>');
                        }
                    },
                    error: function() {
                        $('#content-trace-cleaner-analysis-content').html('<p style="color: red;"><?php echo esc_js(__('Error de conexión.', 'content-trace-cleaner')); ?></p>');
                        $('#content-trace-cleaner-analyze-btn').prop('disabled', false).text('<?php echo esc_js(__('Analizar contenido', 'content-trace-cleaner')); ?>');
                    }
                });
            });

            function displayAnalysisResults(data) {
                var html = '<p><strong><?php echo esc_js(__('Total de posts:', 'content-trace-cleaner')); ?></strong> ' + data.total_posts + '</p>';

                if (data.total_attributes > 0 || data.total_unicode > 0 || data.total_content_references > 0 || data.total_utm_parameters > 0) {
                    html += '<h4><?php echo esc_js(__('Elementos encontrados:', 'content-trace-cleaner')); ?></h4><ul>';

                    if (data.total_attributes > 0) {
                        html += '<li><strong><?php echo esc_js(__('Atributos de rastreo:', 'content-trace-cleaner')); ?></strong> ' + data.total_attributes + '</li>';
                    }
                    if (data.total_unicode > 0) {
                        html += '<li><strong><?php echo esc_js(__('Caracteres Unicode invisibles:', 'content-trace-cleaner')); ?></strong> ' + data.total_unicode + '</li>';
                    }
                    if (data.total_content_references > 0) {
                        html += '<li><strong><?php echo esc_js(__('Referencias de contenido:', 'content-trace-cleaner')); ?></strong> ' + data.total_content_references + '</li>';
                    }
                    if (data.total_utm_parameters > 0) {
                        html += '<li><strong><?php echo esc_js(__('Parámetros UTM:', 'content-trace-cleaner')); ?></strong> ' + data.total_utm_parameters + '</li>';
                    }
                    html += '</ul>';
                } else {
                    html += '<p style="color: green;"><strong><?php echo esc_js(__('No se encontraron elementos para limpiar.', 'content-trace-cleaner')); ?></strong></p>';
                }

                $('#content-trace-cleaner-analysis-content').html(html);

                if (data.has_attributes || data.has_unicode || data.has_content_references || data.has_utm_parameters) {
                    $('#content-trace-cleaner-select-attributes').prop('checked', data.has_attributes);
                    $('#content-trace-cleaner-select-unicode').prop('checked', data.has_unicode);
                    $('#content-trace-cleaner-select-content-references').prop('checked', data.has_content_references);
                    $('#content-trace-cleaner-select-utm-parameters').prop('checked', data.has_utm_parameters);

                    if (data.total_attributes > 0) $('#content-trace-cleaner-attributes-count').text('(' + data.total_attributes + ')');
                    if (data.total_unicode > 0) $('#content-trace-cleaner-unicode-count').text('(' + data.total_unicode + ')');
                    if (data.total_content_references > 0) $('#content-trace-cleaner-content-references-count').text('(' + data.total_content_references + ')');
                    if (data.total_utm_parameters > 0) $('#content-trace-cleaner-utm-parameters-count').text('(' + data.total_utm_parameters + ')');

                    selectedCleanTypes.attributes = data.has_attributes;
                    selectedCleanTypes.unicode = data.has_unicode;
                    selectedCleanTypes.content_references = data.has_content_references;
                    selectedCleanTypes.utm_parameters = data.has_utm_parameters;

                    $('#content-trace-cleaner-selection').show();
                    $('#content-trace-cleaner-start-btn').show();
                }

                $('#content-trace-cleaner-analyze-btn').prop('disabled', false).text('<?php echo esc_js(__('Reanalizar', 'content-trace-cleaner')); ?>');
            }

            $('#content-trace-cleaner-select-all').on('click', function() {
                $('#content-trace-cleaner-select-attributes, #content-trace-cleaner-select-unicode, #content-trace-cleaner-select-content-references, #content-trace-cleaner-select-utm-parameters').prop('checked', true);
                selectedCleanTypes = { attributes: true, unicode: true, content_references: true, utm_parameters: true };
            });

            $('#content-trace-cleaner-select-attributes').on('change', function() { selectedCleanTypes.attributes = $(this).is(':checked'); });
            $('#content-trace-cleaner-select-unicode').on('change', function() { selectedCleanTypes.unicode = $(this).is(':checked'); });
            $('#content-trace-cleaner-select-content-references').on('change', function() { selectedCleanTypes.content_references = $(this).is(':checked'); });
            $('#content-trace-cleaner-select-utm-parameters').on('change', function() { selectedCleanTypes.utm_parameters = $(this).is(':checked'); });

            $('#content-trace-cleaner-start-btn').on('click', function() {
                if (!selectedCleanTypes.attributes && !selectedCleanTypes.unicode && !selectedCleanTypes.content_references && !selectedCleanTypes.utm_parameters) {
                    alert('<?php echo esc_js(__('Selecciona al menos un tipo de limpieza.', 'content-trace-cleaner')); ?>');
                    return;
                }

                if (!confirm('<?php echo esc_js(__('¿Iniciar la limpieza?', 'content-trace-cleaner')); ?>')) return;

                $(this).prop('disabled', true);
                $('#content-trace-cleaner-stop-btn').show();
                $('#content-trace-cleaner-progress').show();
                $('#content-trace-cleaner-result, #content-trace-cleaner-analysis').hide();
                shouldStop = false;
                isProcessing = true;
                offset = 0;

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: { action: 'content_trace_cleaner_get_total', nonce: ajaxNonce },
                    success: function(response) {
                        if (response.success) {
                            totalPosts = response.data.total;
                            processId = response.data.process_id;
                            $('#content-trace-cleaner-progress-text').text('0 / ' + totalPosts);
                            setTimeout(processNextBatch, 500);
                        } else {
                            alert('Error: ' + (response.data.message || 'Error'));
                            resetUI();
                        }
                    },
                    error: function() { alert('<?php echo esc_js(__('Error de conexión.', 'content-trace-cleaner')); ?>'); resetUI(); }
                });
            });

            $('#content-trace-cleaner-stop-btn').on('click', function() {
                shouldStop = true;
                isProcessing = false;
                $('#content-trace-cleaner-status-text').text('<?php echo esc_js(__('Deteniendo...', 'content-trace-cleaner')); ?>');
            });

            function processNextBatch() {
                if (shouldStop || !isProcessing) { resetUI(); return; }

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    timeout: 150000,
                    data: {
                        action: 'content_trace_cleaner_process_batch',
                        nonce: ajaxNonce,
                        process_id: processId,
                        offset: offset,
                        selected_clean_types: JSON.stringify(selectedCleanTypes)
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            offset += data.processed;
                            var pct = (data.total_processed / totalPosts) * 100;
                            $('#content-trace-cleaner-progress-fill').css('width', pct + '%');
                            $('#content-trace-cleaner-progress-text').text(data.total_processed + ' / ' + totalPosts);
                            $('#content-trace-cleaner-status-text').text('<?php echo esc_js(__('Procesando...', 'content-trace-cleaner')); ?> ' + data.total_processed + '/' + totalPosts + ' (Mod: ' + data.total_modified + ')');

                            if (data.is_complete) finishProcess();
                            else setTimeout(processNextBatch, 1000);
                        } else {
                            alert('Error: ' + (response.data.message || 'Error'));
                            resetUI();
                        }
                    },
                    error: function(xhr, status) {
                        if (!shouldStop) {
                            var wait = (status === 'timeout') ? 5000 : 2000;
                            $('#content-trace-cleaner-status-text').text('Error - <?php echo esc_js(__('Reintentando...', 'content-trace-cleaner')); ?>');
                            setTimeout(processNextBatch, wait);
                        } else resetUI();
                    }
                });
            }

            function finishProcess() {
                isProcessing = false;
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: { action: 'content_trace_cleaner_get_progress', nonce: ajaxNonce, process_id: processId },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '<div class="content-trace-cleaner-scan-result" style="background:#f0f0f1;padding:15px;border-left:4px solid #2271b1;">';
                            html += '<h3><?php echo esc_js(__('Limpieza completada', 'content-trace-cleaner')); ?></h3>';
                            html += '<p><strong><?php echo esc_js(__('Posts analizados:', 'content-trace-cleaner')); ?></strong> ' + data.processed + '</p>';
                            html += '<p><strong><?php echo esc_js(__('Posts modificados:', 'content-trace-cleaner')); ?></strong> ' + data.modified + '</p>';
                            html += '</div>';
                            $('#content-trace-cleaner-result').html(html).show();
                        }
                        resetUI();
                        setTimeout(function() { window.location.reload(); }, 3000);
                    },
                    error: function() { resetUI(); setTimeout(function() { window.location.reload(); }, 2000); }
                });
            }

            function resetUI() {
                isProcessing = false;
                $('#content-trace-cleaner-start-btn').prop('disabled', false).hide();
                $('#content-trace-cleaner-stop-btn').hide();
                $('#content-trace-cleaner-progress-fill').css('width', '0%');
                $('#content-trace-cleaner-analyze-btn').prop('disabled', false).text('<?php echo esc_js(__('Analizar contenido', 'content-trace-cleaner')); ?>');
            }
        });
        </script>
        <?php
    }

    /**
     * AJAX: Análisis previo del contenido
     */
    public function ajax_analyze_content() {
        check_ajax_referer('content_trace_cleaner_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Sin permisos.', 'content-trace-cleaner')));
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(array('message' => __('ID de post inválido.', 'content-trace-cleaner')));
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => __('Post no encontrado.', 'content-trace-cleaner')));
        }

        $cleaner = Content_Trace_Cleaner_Cleaner::get_instance();
        $analysis = $cleaner->analyze_content($post->post_content);

        wp_send_json_success($analysis);
    }

    /**
     * AJAX: Analizar todos los posts antes de limpiar
     */
    public function ajax_analyze_all_posts() {
        check_ajax_referer('content_trace_cleaner_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Sin permisos.', 'content-trace-cleaner')));
        }

        global $wpdb;

        $all_post_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ('post', 'page')
             AND post_status = 'publish'
             ORDER BY ID ASC"
        );

        if (empty($all_post_ids)) {
            wp_send_json_success(array(
                'total_posts' => 0,
                'attributes_found' => array(),
                'unicode_found' => array(),
                'content_references_found' => array(),
                'utm_parameters_found' => array(),
                'total_attributes' => 0,
                'total_unicode' => 0,
                'total_content_references' => 0,
                'total_utm_parameters' => 0
            ));
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $cleaner = Content_Trace_Cleaner_Cleaner::get_instance();
        $total_attributes = 0;
        $total_unicode = 0;
        $total_content_references = 0;
        $total_utm_parameters = 0;
        $attributes_found = array();
        $unicode_found = array();
        $content_references_found = array();
        $utm_parameters_found = array();
        $posts_with_data = array();

        $total_posts = count($all_post_ids);

        foreach ($all_post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;

            $analysis = $cleaner->analyze_content($post->post_content);

            $has_data = !empty($analysis['attributes_found']) ||
                       !empty($analysis['unicode_found']) ||
                       !empty($analysis['content_references_found']) ||
                       !empty($analysis['utm_parameters_found']);

            if ($has_data) {
                $posts_with_data[] = array(
                    'post_id' => $post_id,
                    'post_title' => $post->post_title,
                    'post_url' => get_permalink($post_id),
                    'attributes_found' => $analysis['attributes_found'],
                    'unicode_found' => $analysis['unicode_found'],
                    'content_references_found' => isset($analysis['content_references_found']) ? $analysis['content_references_found'] : array(),
                    'utm_parameters_found' => isset($analysis['utm_parameters_found']) ? $analysis['utm_parameters_found'] : array(),
                    'utm_urls_found' => isset($analysis['utm_urls_found']) ? $analysis['utm_urls_found'] : array()
                );
            }

            foreach ($analysis['attributes_found'] as $attr => $count) {
                if (!isset($attributes_found[$attr])) $attributes_found[$attr] = 0;
                $attributes_found[$attr] += $count;
                $total_attributes += $count;
            }

            foreach ($analysis['unicode_found'] as $unicode => $count) {
                if (!isset($unicode_found[$unicode])) $unicode_found[$unicode] = 0;
                $unicode_found[$unicode] += $count;
                $total_unicode += $count;
            }

            if (isset($analysis['content_references_found'])) {
                foreach ($analysis['content_references_found'] as $ref => $count) {
                    if (!isset($content_references_found[$ref])) $content_references_found[$ref] = 0;
                    $content_references_found[$ref] += $count;
                    $total_content_references += $count;
                }
            }

            if (isset($analysis['utm_parameters_found'])) {
                foreach ($analysis['utm_parameters_found'] as $utm => $count) {
                    if (!isset($utm_parameters_found[$utm])) $utm_parameters_found[$utm] = 0;
                    $utm_parameters_found[$utm] += $count;
                    $total_utm_parameters += $count;
                }
            }
        }

        wp_send_json_success(array(
            'total_posts' => $total_posts,
            'sample_size' => $total_posts,
            'attributes_found' => $attributes_found,
            'unicode_found' => $unicode_found,
            'content_references_found' => $content_references_found,
            'utm_parameters_found' => $utm_parameters_found,
            'total_attributes' => $total_attributes,
            'total_unicode' => $total_unicode,
            'total_content_references' => $total_content_references,
            'total_utm_parameters' => $total_utm_parameters,
            'has_attributes' => $total_attributes > 0,
            'has_unicode' => $total_unicode > 0,
            'has_content_references' => $total_content_references > 0,
            'has_utm_parameters' => $total_utm_parameters > 0,
            'posts_with_data' => $posts_with_data
        ));
    }
}
