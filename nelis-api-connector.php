<?php
/**
 * Plugin Name: Nelis API Connector
 * Description: Connecte WordPress à l'API Nelis v4 pour récupérer les contacts avec un champ personnalisé de type date.
 * Version: 1.0
 * Author: Manus AI
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

// Initialiser les classes
function nelis_api_connector_init() {
    new Nelis_API_Settings();
    new Nelis_API_Shortcode();
}
add_action( 'plugins_loaded', 'nelis_api_connector_init' );


