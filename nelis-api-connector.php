<?php
/**
 * Plugin Name: Nelis API Connector
 * Description: Connecte WordPress à l'API Nelis v4 pour récupérer les contacts avec un champ personnalisé de type date.
 * Version: 1.2.1
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
require_once plugin_dir_path( __FILE__ ) . 'includes/class-nelis-cron-manager.php';

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

// Initialiser les classes générales
function nelis_api_connector_init() {
    new Nelis_API_Settings();
    new Nelis_API_Shortcode();
}

// Initialiser les classes admin
function nelis_api_connector_admin_init() {
    if (is_admin()) {
        new NelisBrevoSyncAdmin();
        new NelisCronManager();
    }
}

add_action( 'plugins_loaded', 'nelis_api_connector_init' );
add_action( 'init', 'nelis_api_connector_admin_init' );


