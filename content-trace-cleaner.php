<?php
/**
 * Plugin Name: Content Trace Cleaner
 * Plugin URI: https://github.com/tete-cordobes/content-trace-cleaner
 * Description: Limpia rastros de LLMs y atributos data-* del contenido de WordPress. Versión limpia sin telemetría.
 * Version: 1.0.0
 * Author: tete-cordobes
 * Author URI: https://github.com/tete-cordobes
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: content-trace-cleaner
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

// Definir constantes del plugin
define('CONTENT_TRACE_CLEANER_VERSION', '1.0.0');
define('CONTENT_TRACE_CLEANER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONTENT_TRACE_CLEANER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CONTENT_TRACE_CLEANER_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Clase principal del plugin
 */
class Content_Trace_Cleaner {

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
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Cargar dependencias
     */
    private function load_dependencies() {
        // Cargar clases del plugin
        require_once CONTENT_TRACE_CLEANER_PLUGIN_DIR . 'includes/class-content-trace-cleaner-activator.php';
        require_once CONTENT_TRACE_CLEANER_PLUGIN_DIR . 'includes/class-content-trace-cleaner-logger.php';
        require_once CONTENT_TRACE_CLEANER_PLUGIN_DIR . 'includes/class-content-trace-cleaner-cleaner.php';
        require_once CONTENT_TRACE_CLEANER_PLUGIN_DIR . 'includes/class-content-trace-cleaner-cache.php';

        // Cargar admin solo en el panel de administración
        if (is_admin()) {
            require_once CONTENT_TRACE_CLEANER_PLUGIN_DIR . 'includes/class-content-trace-cleaner-admin.php';
        }
    }

    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Hooks de activación y desactivación
        register_activation_hook(__FILE__, array('Content_Trace_Cleaner_Activator', 'activate'));
        register_deactivation_hook(__FILE__, array('Content_Trace_Cleaner_Activator', 'deactivate'));

        // Inicializar componentes
        add_action('plugins_loaded', array($this, 'init_components'));

        // Cargar traducciones
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Inicializar componentes del plugin
     */
    public function init_components() {
        // Inicializar logger
        Content_Trace_Cleaner_Logger::get_instance();

        // Inicializar cleaner
        Content_Trace_Cleaner_Cleaner::get_instance();

        // Inicializar cache handler
        Content_Trace_Cleaner_Cache::get_instance();

        // Inicializar admin
        if (is_admin()) {
            Content_Trace_Cleaner_Admin::get_instance();
        }
    }

    /**
     * Cargar traducciones
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'content-trace-cleaner',
            false,
            dirname(CONTENT_TRACE_CLEANER_PLUGIN_BASENAME) . '/languages'
        );
    }
}

// Inicializar el plugin
function content_trace_cleaner_init() {
    return Content_Trace_Cleaner::get_instance();
}

// Arrancar el plugin
content_trace_cleaner_init();
