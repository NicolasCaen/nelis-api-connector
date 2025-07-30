<?php
/**
 * Client API pour Nelis v4.
 */

class Nelis_API_Client {

    private $client_id;
    private $client_secret;
    private $username;
    private $password;
    private $access_token;

    public function __construct() {
        $this->client_id = get_option( 'nelis_api_client_id' );
        $this->client_secret = get_option( 'nelis_api_client_secret' );
        $this->username = get_option( 'nelis_api_username' );
        $this->password = get_option( 'nelis_api_password' );
    }

    /**
     * Obtient un token d'accès via l'API Nelis.
     */
    public function get_access_token() {
        $url = 'https://amavea.mynelis.com/token'; 

        $body = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        );

        $response = wp_remote_post( $url, array(
            'method' => 'POST',
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
         
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( isset( $data['access_token'] ) ) {
            $this->access_token = $data['access_token'];
            return $this->access_token;
        }
   
        return false;
    }

    /**
     * Récupère tous les contacts avec un champ personnalisé de type date.
     */
    public function get_contacts_by_date_field( $date_value = null ) {
        if ( ! $this->access_token ) {
            $this->get_access_token();
        }

        if ( ! $this->access_token ) {
            return false;
        }

        $url = 'https://amavea.mynelis.com'; 

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ),
        );

        // Ajouter le paramètre pour récupérer les champs personnalisés
        $url .= '?with_custom_values=true';

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! isset( $data ) || ! is_array( $data ) ) {
            return false;
        }

        // Filtrer les contacts par champ personnalisé de type date si spécifié
        $custom_field_name = get_option( 'nelis_api_custom_field_name' );
        if ( $custom_field_name && $date_value ) {
            $filtered_contacts = array();
            foreach ( $data as $contact ) {
                if ( isset( $contact['custom_fields'] ) && is_array( $contact['custom_fields'] ) ) {
                    foreach ( $contact["custom_fields"] as $field ) {
                        if ( isset( $field["id"] ) && $field["id"] == $custom_field_name ) {
                            if ( isset( $field['value'] ) && $field['value'] === $date_value ) {
                                $filtered_contacts[] = $contact;
                            }
                        }
                    }
                }
            }
            return $filtered_contacts;
        }

        return $data;
    }

    /**
     * Récupère les contacts créés depuis une date donnée.
     */
    public function get_contacts_created_since( $date ) {
        if ( ! $this->access_token ) {
            $this->get_access_token();
        }

        if ( ! $this->access_token ) {
            return false;
        }

        $url = 'https://amavea.mynelis.com/api/v4/people/created'; // Remplacez par l'URL réelle de votre API

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ),
        );

        // Ajouter les paramètres de date et de champs personnalisés
        $url .= '?date=' . urlencode( $date ) . '&with_custom_values=true';

        $response = wp_remote_get( $url, $args );
var_dump($response);
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return $data;
    }

    /**
     * Récupère les contacts mis à jour depuis une date donnée.
     */
    public function get_contacts_updated_since( $date ) {
        if ( ! $this->access_token ) {
            $this->get_access_token();
        }

        if ( ! $this->access_token ) {
            return false;
        }

        $url = 'https://amavea.mynelis.com/api/v4/people/updated'; 

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ),
        );

        // Ajouter les paramètres de date et de champs personnalisés
        $url .= '?date=' . urlencode( $date ) . '&with_custom_values=true';

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return $data;
    }
        /**
     * Récupère les contacts mis à jour depuis une date donnée.
     */
    public function get_contact_by_id( $id ) {
        if ( ! $this->access_token ) {
            $this->get_access_token();
        }

        if ( ! $this->access_token ) {
            return false;
        }

        $url = 'https://amavea.mynelis.com/api/v4/people/'; 

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ),
        );

        // Ajouter les paramètres de date et de champs personnalisés
        $url .= $id;

        $response = wp_remote_get( $url, $args );
       
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return $data;
    }
}
