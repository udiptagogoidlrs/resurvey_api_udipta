<?php
    defined('BASEPATH') OR exit('No direct script access allowed');

    use Firebase\JWT\JWT;
    // use Firebase\JWT\Key;

    function jwtencode($payload) {
        // $CI =& get_instance();
        // $CI->load->library('JWT');
        $token = JWT::encode($payload, JWT_SECRET_KEY, 'HS256');
        return $token;
    }
    
    function jwtdecode($jwt_token) {
        $decodedToken = JWT::decode($jwt_token, JWT_SECRET_KEY, ['HS256']);
        return $decodedToken;
    }
    function jwtVerify($jwt_token) {
        $decoded = jwtdecode($jwt_token);
        if(!$decoded){
            $this->output
                ->set_status_header(401)
                ->set_output(json_encode(['error' => 'Invalid or expired token']));
            exit;
        }
        return $decoded;
    }

?>