<?
if (!defined('PIXNET_LIB_INCLUDED')) {
  define('PIXNET_LIB_INCLUDED', true);

  class PixnetLib {

    const PIXNET_ACCESS_TOKEN_KEY = 'pixnet_access_token';
    const PIXNET_REFRESH_TOKEN_KEY = 'pixnet_refresh_token';
    const PIXNET_LAST_REFRESHED_AT_KEY = 'pixnet_last_refreshed_at';
    const PIXNET_USERNAME_KEY = 'pixnet_username';
    const PIXNET_REDIRECT_TO_KEY = 'pixnet_redirect_to';
    const PIXNET_API_ROOT = 'https://emma.pixnet.cc';
    const TOKEN_TIMEOUT_LIMIT = 3000; // Service setting is 3600, we refresh it early

    static private $_CLIENT_MAPPINGS = array(
      array(
        'WEBSITE_URL'     => 'YOUR WEBSITE ROOT',
        'CONSUMER_KEY'    => 'YOUR CONSUMER KEY',
        'CONSUMER_SECRET' => 'YOUR CONSUMER SECRET',
        'CALLBACK_URL'    => 'YOUR CALLBACK URL'
      )
    );

    //public method

    static public function isSignin() {
      return $_SESSION[self::PIXNET_ACCESS_TOKEN_KEY];
    }

    static public function signOut() {
      $_SESSION[self::PIXNET_ACCESS_TOKEN_KEY] = '';
      $_SESSION[self::PIXNET_REFRESH_TOKEN_KEY] = '';
      $_SESSION[self::PIXNET_LAST_REFRESHED_AT_KEY] = '';
      $_SESSION[self::PIXNET_USERNAME_KEY] = '';
    }

    static public function getAuthorizeUrl($redirect_uri = '') {
      $client = self::_get_client();
      $_SESSION[self::PIXNET_REDIRECT_TO] = $redirect_uri;
      $query_string = http_build_query(array(
        'redirect_uri'  => $client['CALLBACK_URL'],
        'client_id'     => $client['CONSUMER_KEY'],
        'response_type' => 'code'
      ));

      return self::PIXNET_API_ROOT . "/oauth2/authorize?{$query_string}";
    }

    static public function getAccessToken($code) {
      $client = self::_get_client();
      $response = self::_call_api('/oauth2/grant', array(
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $client['CALLBACK_URL'], 
        'client_id'     => $client['CONSUMER_KEY'],
        'client_secret' => $client['CONSUMER_SECRET']
      ));

      $result = json_decode($response);
      if ($result->access_token) {
        $_SESSION[self::PIXNET_ACCESS_TOKEN_KEY] = $result->access_token;
        if ($result->refresh_token) {
          $_SESSION[self::PIXNET_REFRESH_TOKEN_KEY] = $result->refresh_token;
        }
        $_SESSION[self::PIXNET_LAST_REFRESHED_AT_KEY] = time();
        self::_update_username();
        return TRUE;
      } else {
        self::_pixnet_error_handler($result);
        throw new Exception("[PIXNET]get access token by code fail, unknown error.");
        return FALSE;
      }
    }

    static public function callApi($url, $datas) {
      if (!self::isSignin()) {
        throw new Exception('not signin pixnet yet.');
      }

      if (self::_is_token_timeout()) {
        self::_refresh_token();
      }

      $datas['access_token'] = $_SESSION[self::PIXNET_ACCESS_TOKEN_KEY];
      $datas['format'] = 'json';
      if ($_SESSION[self::PIXNET_USERNAME_KEY]) {
        $datas['user'] = $_SESSION[self::PIXNET_USERNAME_KEY];
      }
      
      $response = self::_call_api($url, $datas);
      $decoded_response = json_decode($response);
      self::_pixnet_error_handler($decoded_response);
      return $decoded_response;
    }

    // ---------------------------------------------------------------

    static public function getUsername() {
      if (!$_SESSION[self::PIXNET_USERNAME_KEY]) {
        self::_update_username();
      }
      return $_SESSION[self::PIXNET_USERNAME_KEY];
    }

    // /blog
    static public function getBlog() {
      return self::callApi('/blog');
    }
  
    // /blog/articles
    static public function getBlogArticles($page = 1, $per_page = 100) {
      return self::callApi('/blog/articles', array(
        'page'      => $page,
        'per_page'  => $per_page,
        'trim_user' => 1
      ));
    }

    // /blog/article/:id
    static public function getBlogArticle($id) {
      return self::callApi('/blog/articles/' . $id);
    }

    // /albu/sets/
    static public function getAlbumSets($page = 1, $per_page = 100) {
      return self::callApi('/album/sets/', array(
        'page'        => $page,
        'per_page'    => $per_page,
        'trim_user'   => 1
      ));
    }

    // /album/sets/:id/elements
    static public function getAlbumSetElements($set_id, $page = 1, $per_page = 100) {
      return self::callApi("/album/sets/{$set_id}/elements", array(
        'type'        => 'pic',
        'with_detail' => 1,
        'page'        => $page,
        'per_page'    => $per_page,
        'trim_user'   => 1
      ));
    }

    // ---------------------------------------------------------------
    //private method

    private function _refresh_token() {
      $client = self::_get_cilent();
      $response = self::_call_api('/oauth2/grant', array(
        'grant_type'    => 'refresh_token',
        'refresh_token' => $_SESSION[self::PIXNET_REFRESH_TOKEN_KEY],
        'client_id'     => $client['CONSUMER_KEY'],
        'client_secret' => $client['CONSUMER_SECRET']
      ));

      $result = json_decode($response);
      self::_pixnet_error_handler($result);
      if ($result->access_token) {
        $_SESSION[self::PIXNET_ACCESS_TOKEN_KEY] = $result->access_token;
        if ($result->refresh_token) {
          $_SESSION[self::PIXNET_REFRESH_TOKEN_KEY] = $result->refresh_token;
        }
        $_SESSION[self::PIXNET_LAST_REFRESHED_AT_KEY] = time();
        return TRUE;
      } else {
        self::_pixnet_error_handler($result);
        throw new Exception("[PIXNET]refresh token fail, unknown error.");
        return FALSE;
      }
    }

    private function _is_token_timeout() {
      if (!$_SESSION[self::PIXNET_LAST_REFRESHED_AT_KEY]) {
        return TRUE;
      }
      $now = time();
      if (($now - self::TOKEN_TIMEOUT_LIMIT) > $_SESSION[self::PIXNET_LAST_REFRESHED_AT_KEY]) {
        return TRUE;
      }
      return FALSE;
    }

    private function _update_username() {
      if (!self::isSignin()) {
        return;
      }

      $result = self::callApi('/account');
      if ($result->account->name) {
        $_SESSION[self::PIXNET_USERNAME_KEY] = $result->account->name;
      }
    }

    private function _pixnet_error_handler($result) {
      if ($result->error) {
        $error = $result->error;
        $error_description = $result->error_description;
        if (!$error_description) {
          //try message
          $error_description = $result->message;
        }
        $error_msg = "[PIXNET] error: {$error}, error_description: {$error_description}";
        throw new Exception($error_msg);
      }
    }

    private function _call_api($url, $datas, $method = 'GET') {
      //set curl parameters.
      $ch = curl_init();

      curl_setopt($ch, CURLOPT_URL, self::PIXNET_API_ROOT. $url);
      curl_setopt($ch, CURLOPT_VERBOSE, 1);
      
      //turn off the server and peer verification (TrustManager Concept)
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    
      if ($datas) {
        if ($method == 'GET') {
          curl_setopt($ch, CURLOPT_URL, self::PIXNET_API_ROOT . "{$url}?" . http_build_query($datas));
        } else if ($method == 'POST') {
          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
        }
      }
    
      $http_response = curl_exec($ch);
      curl_close($ch);
     
      return $http_response;
    }

    private function _get_client() {
      if(isset($HTTP_SERVER_VARS[HTTPS])){ 
        $_FULL_URL = 'https://' . $_SERVER[HTTP_HOST];
      } else {
        $_FULL_URL = 'http://' . $_SERVER[HTTP_HOST]; 
      }

      foreach (self::$_CLIENT_MAPPINGS as $client) {
        if ($_FULL_URL == $client['WEBSITE_URL']) {
          Logger::msg('match client');
          return $client;
        }
      }

      //can't match any client data, will try to use the first client
      return self::$_CLIENT_MAPINGS[0];
    }  

  }//end class
}//end defined
?>