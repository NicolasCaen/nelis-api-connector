<?php
/**
 * Gère les shortcodes du plugin Nelis API Connector.
 */

class Nelis_API_Shortcode {

    public function __construct() {
        add_shortcode( 'nelis_contacts', array( $this, 'display_contacts_shortcode' ) );
        add_shortcode( 'nelis_contact', array( $this, 'display_contact_by_id_shortcode' ) );
        add_shortcode( 'nelis_contacts_by_date', array( $this, 'display_contacts_by_date_shortcode' ) );
        add_shortcode( 'nelis_contacts_created_since', array( $this, 'display_contacts_created_since_shortcode' ) );
        add_shortcode( 'nelis_contacts_updated_since', array( $this, 'display_contacts_updated_since_shortcode' ) );
    }

    /**
     * Shortcode pour afficher tous les contacts.
     * Usage: [nelis_contacts]
     */
    public function display_contacts_shortcode( $atts ) {
        $api_client = new Nelis_API_Client();
    
        $contacts = $api_client->get_contacts_by_date_field();

        if ( ! $contacts ) {
            return '<p>Aucun contact trouvé ou erreur lors de la récupération des données.</p>';
        }

        return $this->format_contacts_output( $contacts );
    }
    public function display_contact_by_id_shortcode( $atts ) {
        $api_client = new Nelis_API_Client();

        $atts = shortcode_atts( array(
            'id' => '',
        ), $atts);
    
        $contact = $api_client->get_contact_by_id ($atts['id'] );
        ?>
        <pre>
<?php var_dump($contact); ?>
    </pre>
<?php
        if ( ! $contact ) {
            return '<p>Aucun contact trouvé aveccet ID.</p>';
        }

        return ;
    }
    
    /**
     * Shortcode pour afficher les contacts filtrés par un champ personnalisé de type date.
     * Usage: [nelis_contacts_by_date date="2023-01-01"]
     */
    public function display_contacts_by_date_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'date' => '',
        ), $atts, 'nelis_contacts_by_date' );

        if ( empty( $atts['date'] ) ) {
            return '<p>Veuillez spécifier une date avec l\'attribut "date".</p>';
        }

        $api_client = new Nelis_API_Client();
        $contacts = $api_client->get_contacts_by_date_field( $atts['date'] );

        if ( ! $contacts ) {
            return '<p>Aucun contact trouvé pour cette date ou erreur lors de la récupération des données.</p>';
        }

        return $this->format_contacts_output( $contacts );
    }

    /**
     * Shortcode pour afficher les contacts créés depuis une date donnée.
     * Usage: [nelis_contacts_created_since date="2023-01-01"]
     */
    public function display_contacts_created_since_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'date' => '',
        ), $atts, 'nelis_contacts_created_since' );

        if ( empty( $atts['date'] ) ) {
            return '<p>Veuillez spécifier une date avec l\'attribut "date".</p>';
        }

        $api_client = new Nelis_API_Client();
        $contacts = $api_client->get_contacts_created_since( $atts['date'] );

        if ( ! $contacts ) {
            return '<p>Aucun contact créé depuis cette date ou erreur lors de la récupération des données.</p>';
        }

        return $this->format_contacts_output( $contacts );
    }

    /**
     * Shortcode pour afficher les contacts mis à jour depuis une date donnée.
     * Usage: [nelis_contacts_updated_since date="2023-01-01"]
     */
    public function display_contacts_updated_since_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'date' => '',
        ), $atts, 'nelis_contacts_updated_since' );

        if ( empty( $atts['date'] ) ) {
            return '<p>Veuillez spécifier une date avec l\'attribut "date".</p>';
        }

        $api_client = new Nelis_API_Client();
        $contacts = $api_client->get_contacts_updated_since( $atts['date'] );

        if ( ! $contacts ) {
            return '<p>Aucun contact mis à jour depuis cette date ou erreur lors de la récupération des données.</p>';
        }

        return $this->format_contacts_output( $contacts );
    }

    /**
     * Formate l'affichage des contacts.
     */
    private function format_contacts_output( $contacts ) {
        if ( empty( $contacts ) ) {
            return '<p>Aucun contact à afficher.</p>';
        }

        $output = '<div class="nelis-contacts-list">';
        
        foreach ( $contacts as $contact ) {
            $output .= '<div class="nelis-contact-item" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 5px;">';
            
            // Nom et prénom
            if ( isset( $contact['firstname'] ) || isset( $contact['lastname'] ) ) {
                $output .= '<h3 style="margin-top: 0;">';
                $output .= esc_html( ( $contact['firstname'] ?? '' ) . ' ' . ( $contact['lastname'] ?? '' ) );
                $output .= '</h3>';
            }

            // Email
            if ( isset( $contact['email'] ) && ! empty( $contact['email'] ) ) {
                $output .= '<p><strong>Email:</strong> ' . esc_html( $contact['email'] ) . '</p>';
            }

            // Fonction
            if ( isset( $contact['function'] ) && ! empty( $contact['function'] ) ) {
                $output .= '<p><strong>Fonction:</strong> ' . esc_html( $contact['function'] ) . '</p>';
            }

            // Champs personnalisés
            if ( isset( $contact['custom_fields'] ) && is_array( $contact['custom_fields'] ) ) {
                $output .= '<div class="custom-fields">';
                $output .= '<strong>Champs personnalisés:</strong><br>';
                foreach ( $contact['custom_fields'] as $field ) {
                    if ( isset( $field['name'] ) && isset( $field['value'] ) ) {
                        $output .= '<span style="display: inline-block; margin-right: 15px;">';
                        $output .= '<em>' . esc_html( $field['name'] ) . ':</em> ' . esc_html( $field['value'] );
                        $output .= '</span>';
                    }
                }
                $output .= '</div>';
            }

            $output .= '</div>';
        }
        
        $output .= '</div>';

        return $output;
    }
}

