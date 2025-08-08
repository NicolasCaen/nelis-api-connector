<?php
/**
 * Gère la page de réglages du plugin Nelis API Connector.
 */

class Nelis_API_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_init' ) );
    }

    public function add_admin_menu() {
        add_options_page(
            'Nelis API Settings',
            'Nelis API',
            'manage_options',
            'nelis-api-connector',
            array( $this, 'options_page_html' )
        );
    }

    public function settings_init() {
        register_setting( 'nelis_api_connector_group', 'nelis_api_client_id' );
        register_setting( 'nelis_api_connector_group', 'nelis_api_client_secret' );
        register_setting( 'nelis_api_connector_group', 'nelis_api_username' );
        register_setting( 'nelis_api_connector_group', 'nelis_api_password' );
        register_setting( 'nelis_api_connector_group', 'nelis_api_custom_field_name' );

        add_settings_section(
            'nelis_api_connector_section',
            'Paramètres de connexion à l\'API Nelis',
            array( $this, 'settings_section_callback' ),
            'nelis-api-connector'
        );

        add_settings_field(
            'nelis_api_client_id_field',
            'Client ID',
            array( $this, 'client_id_callback' ),
            'nelis-api-connector',
            'nelis_api_connector_section'
        );

        add_settings_field(
            'nelis_api_client_secret_field',
            'Client Secret',
            array( $this, 'client_secret_callback' ),
            'nelis-api-connector',
            'nelis_api_connector_section'
        );

        add_settings_field(
            'nelis_api_username_field',
            'Nom d\'utilisateur',
            array( $this, 'username_callback' ),
            'nelis-api-connector',
            'nelis_api_connector_section'
        );

        add_settings_field(
            'nelis_api_password_field',
            'Mot de passe',
            array( $this, 'password_callback' ),
            'nelis-api-connector',
            'nelis_api_connector_section'
        );

        add_settings_field(
            'nelis_api_custom_field_name_field',
            'ID du champ personnalisé (date)',
            array( $this, 'custom_field_name_callback' ),
            'nelis-api-connector',
            'nelis_api_connector_section'
        );
    }

    public function settings_section_callback() {
        echo '<p>Entrez vos identifiants API Nelis ci-dessous.</p>';
    }

    public function client_id_callback() {
        $client_id = get_option( 'nelis_api_client_id' );
        echo '<input type="text" name="nelis_api_client_id" value="' . esc_attr( $client_id ) . '" />';
    }

    public function client_secret_callback() {
        $client_secret = get_option( 'nelis_api_client_secret' );
        echo '<input type="text" name="nelis_api_client_secret" value="' . esc_attr( $client_secret ) . '" />';
    }

    public function username_callback() {
        $username = get_option( 'nelis_api_username' );
        echo '<input type="text" name="nelis_api_username" value="' . esc_attr( $username ) . '" />';
    }

    public function password_callback() {
        $password = get_option( 'nelis_api_password' );
        echo '<input type="password" name="nelis_api_password" value="' . esc_attr( $password ) . '" />';
    }

    public function custom_field_name_callback() {
        $custom_field_name = get_option( 'nelis_api_custom_field_name' );
        echo '<input type="text" name="nelis_api_custom_field_name" value="' . esc_attr( $custom_field_name ) . '" />';
    }

    public function options_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'nelis_api_connector_group' );
                do_settings_sections( 'nelis-api-connector' );
                submit_button( 'Sauvegarder les modifications' );
                ?>
            </form> 
            <?php echo do_shortcode('[nelis_adherents]') ?>
            
        </div>
        <?php
    }
}

