<?php
namespace AppZz\Http\Spotify;
use AppZz\Helpers\Arr;
use AppZz\Http\CurlClient;

class Request {

    private $_client_id;
    private $_client_secret;
    private $_token;
    private $_raw_output = true;
    private $_update_token = false;
    private $_total_errors = 0;

    private $_body;
    private $_headers;
    private $_status;

    const MAX_ERRORS = 3;
    const USER_AGENT = 'AppZz Spotify Client';
    const AUTH_URL   = 'https://accounts.spotify.com/api/token';
    const SEARCH_URL = 'https://api.spotify.com/v1/search';
    const ALBUMS_URL = 'https://api.spotify.com/v1/albums/%s';

    /**
     * @param string $client_id
     * @param string $client_secret
     * @param array  $options
     */
    public function __construct ($client_id = '', $client_secret = '', array $options = [])
    {
        $this->_client_id = $client_id;
        $this->_client_secret = $client_secret;
        $this->_update_token = Arr::get ($options, 'update_token', false);
        $this->_raw_output = Arr::get ($options, 'raw_output', false);
    }

    /**
     * get|set token
     * @param  string $token
     * @return string
     */
    public function token ($token = '')
    {
        if ( ! empty ($token)) {
            $this->_token = $token;
        }

        return $this->_token;
    }

    /**
     * Try do auth and get token
     * @return bool
     */
    public function auth ()
    {
        $params  = ['grant_type'=>'client_credentials'];
        $headers = ['Authorization' => 'Basic ' . base64_encode($this->_client_id.':'.$this->_client_secret)];
        $this->_request(Request::AUTH_URL, CurlClient::POST, $params, $headers);
        $token = Arr::get ($this->_body, 'access_token');
        $this->token($token);
        return ( ! empty ($token));
    }

    /**
     * Search
     * @param  string  $q
     * @param  mixed  $type
     * @param  integer $limit
     * @return mixed
     */
    public function search ($q, $type = 'album', $limit = 20)
    {
        if (is_array ($type)) {
            $type = implode (',', $type);
        }

        $limit = intval ($limit);

        $params = [
            'q'     => $q,
            'type'  => $type,
            'limit' => $limit
        ];

        $this->_request(Request::SEARCH_URL, CurlClient::GET, $params);

        if ( ! $this->_raw_output) {
            if ($type == 'album') {
                $this->_populate_album ($this->_body);
            } elseif ($type == 'artist') {
                $this->_populate_artist ($this->_body);
            }
        }

        return $this->_body;
    }

    /**
     * Get album by id|url
     * @param  string $album_id spotify id | url
     * @return mixed
     */
    public function album ($album_id = '')
    {
        if (preg_match ('#album\/([a-z0-9]+)\/?#iu', $album_id, $parts)) {
            $album_id = $parts[1];
        }

        $url = sprintf (Request::ALBUMS_URL, $album_id);

        $this->_request($url, CurlClient::GET);

        if ( ! $this->_raw_output) {
            $this->_populate_album ($this->_body);
        }

        return $this->_body;
    }

    private function _populate_album (&$result)
    {
        if (empty ($result)) {
            return false;
        }

        $ret = [];

        $items = Arr::path ($result, 'albums.items');

        if ( ! $items) {
            $items = [$result];
        }

        if ($items) {
            foreach ($items as $item) {
                $artist = Arr::get ($item, 'artists', array());
                $images = Arr::get ($item, 'images', array());
                $artist = array_shift ($artist);
                $images = array_shift ($images);
                $data = [];
                $data['id'] = Arr::get ($item, 'id', '');
                $data['year'] = mb_substr (Arr::get ($item, 'release_date', ''), 0, 4);
                $data['artist'] = Arr::get ($artist, 'name', '');
                $data['artist_url'] = Arr::path ($artist, 'external_urls.spotify', '.', '');
                $data['artwork'] = Arr::get ($images, 'url');
                $data['album'] = Arr::get ($item, 'name', '');
                $data['album_url'] = Arr::path ($item, 'external_urls.spotify', '.', '');
                $ret[] = $data;
            }
        }

        if (count ($ret) === 1) {
            $ret = array_shift ($ret);
        }

        $result = $ret;
    }

    private function _populate_artist (&$result)
    {
        if (empty ($result)) {
            return false;
        }

        $ret = [];

        $items = Arr::path ($result, 'artists.items');

        if ($items) {
            foreach ($items as $item) {
                $images = Arr::get ($item, 'images', array ());
                $images = array_shift ($images);
                $data = [];
                $data['id']         = Arr::get ($item, 'id', '');
                $data['artist']     = Arr::get ($item, 'name', '');
                $data['artist_url'] = Arr::path ($item, 'external_urls.spotify', '.', '');
                $data['artwork']    = Arr::get ($images, 'url');
                $data['genres']     = Arr::get ($item, 'genres');
                $ret[] = $data;
            }
        }

        if (count ($ret) === 1) {
            $ret = array_shift ($ret);
        }

        $result = $ret;
    }

    private function _request ($url, $method = CurlClient::GET, array $params = [], array $headers = [])
    {
        if ( ! empty ($this->_token)) {
            $headers['Authorization'] = 'Bearer ' . $this->_token;
        }

        $response = CurlClient::request ($url, $method, $params, $headers)
                ->user_agent(Request::USER_AGENT)
                ->form()
                ->accept('json')
                ->send();

        $this->_status = $response->get_status();
        $this->_body = $response->get_body();
        $this->_headers = $response->get_headers()->asArray();

        if (in_array($this->_status, [400,401,403]) AND $url != Request::AUTH_URL AND $this->_update_token AND ++$this->_total_errors <= Request::MAX_ERRORS) {
            $this->_token = '';
            if ($this->auth()) {
                $this->_request ($url, $method, $params, $headers);
            }

            return $this;
        }

        if ($this->_status > 202) {
            $error = Arr::get ($this->_body, 'error');
            $error_description = Arr::get ($this->_body, 'error_description');

            if (is_array($error)) {
                $error = Arr::get ($error, 'message');
            }

            throw new Exception\WebApiError ($error, $error_description, $this->_status);
        }

        return $this;
    }
}
