<?php
/**
 * Plugin Name: Nelis API Connector
 * Description: Connecte WordPress à l'API Nelis v4 pour récupérer les contacts avec un champ personnalisé de type date.
 * Version: 1.4.1
 * Author: Nicolas GEHIN
 */

// Empêcher l'accès direct au fichier
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Inclure les fichiers nécessaires
require_once plugin_dir_path( __FILE__ ) . 'includes/class-nelis-api-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-nelis-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-nelis-api-shortcode.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-brevo-connector.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-contact-synchronizer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-nelis-brevo-synch-admin.php';
// Charger les routes de cron seulement après l'initialisation de WordPress
add_action('init', function() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-nelis-brevo-cron-routes.php';
});

// Hook pour activer le flush des règles de réécriture à l'activation du plugin
register_activation_hook(__FILE__, function() {
    // Forcer le rechargement des règles de réécriture
    delete_option('nelis_brevo_rewrite_rules_flushed');
});

// Hook pour désactiver et nettoyer à la désactivation
register_deactivation_hook(__FILE__, function() {
    delete_option('nelis_brevo_rewrite_rules_flushed');
    flush_rewrite_rules();
});

// Ajouter un cron job pour la sync automatique
function nelis_brevo_schedule_sync() {
    if (!wp_next_scheduled('nelis_brevo_daily_sync')) {
        wp_schedule_event(time(), 'daily', 'nelis_brevo_daily_sync');
    }
}
add_action('wp', 'nelis_brevo_schedule_sync');

add_action('nelis_brevo_daily_sync', function() {
    $sync = new ContactSynchronizer();
    $sync->incremental_sync();
});

// Initialiser les classes
function nelis_api_connector_init() {
    new Nelis_API_Settings();
    new Nelis_API_Shortcode();
}
add_action( 'plugins_loaded', 'nelis_api_connector_init' );


