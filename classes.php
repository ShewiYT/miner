<?php
class CMD
{
    function getRAM($true = false)
    {
        if ($true) {
            $bytes = memory_get_usage();
            if ($bytes >= 1073741824) {
                $bytes = number_format($bytes / 1073741824, 2) . ' GB';
            } elseif ($bytes >= 1048576) {
                $bytes = number_format($bytes / 1048576, 2) . ' MB';
            } elseif ($bytes >= 1024) {
                $bytes = number_format($bytes / 1024, 2) . ' KB';
            } elseif ($bytes > 1) {
                $bytes = $bytes . ' байты';
            } elseif ($bytes == 1) {
                $bytes = $bytes . ' байт';
            } else {
                $bytes = '0 байтов';
            }
            return $bytes;
        } else return memory_get_usage();
    }
    function setTitle($title = '', $ram = false)
    {
        if ($ram) $text = ' (' . $this->getRAM(true) . ')';
        return cli_set_process_title("$title$text");
    }
    function write($text = '', $color = '', $colorBg = '')
    {
        echo $text;
    }
    function writeln($text = '')
    {
        echo $text . PHP_EOL;
    }
    function cls()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return popen('cls', 'w');
        } else {
            return popen('clear', 'w');
        }
    }
}

class WSClient
{
    protected $socket, $is_connected = false, $is_closing = false, $last_opcode = null,
        $close_status = null;

    protected $socket_uri;

    protected static $opcodes = array(
        'text'   => 1,
        'binary' => 2,
        'close'  => 8,
        'ping'   => 9,
        'pong'   => 10,
    );

    public function __construct($uri, $options = array())
    {
        $this->options = $options;

        if (!array_key_exists('timeout', $this->options)) $this->options['timeout'] = 5;
        if (!array_key_exists('user-agent', $this->options)) $this->options['user-agent'] = 'websocket-client-php';

        $this->socket_uri = $uri;
    }

    public function __destruct()
    {
        if ($this->socket) {
            if (get_resource_type($this->socket) === 'stream') fclose($this->socket);
            $this->socket = null;
        }
    }


    public function getLastOpcode()
    {
        return $this->last_opcode;
    }
    public function getCloseStatus()
    {
        return $this->close_status;
    }
    public function isConnected()
    {
        return $this->is_connected;
    }

    public function setTimeout($timeout)
    {
        $this->options['timeout'] = $timeout;

        if ($this->socket && get_resource_type($this->socket) === 'stream') {
            stream_set_timeout($this->socket, $timeout);
        }
    }

    public function send($payload, $opcode = 'text', $masked = true)
    {
        if (!$this->is_connected) $this->connect();

        if (!in_array($opcode, array_keys(self::$opcodes))) {
            exit("Bad opcode '$opcode'.  Try 'text' or 'binary'.");
        }

        $frame_head_binstr = '';

        $final = true;
        $frame_head_binstr .= $final ? '1' : '0';

        $frame_head_binstr .= '000';

        $frame_head_binstr .= sprintf('%04b', self::$opcodes[$opcode]);

        $frame_head_binstr .= $masked ? '1' : '0';

        $payload_length = strlen($payload);
        if ($payload_length > 65535) {
            $frame_head_binstr .= decbin(127);
            $frame_head_binstr .= sprintf('%064b', $payload_length);
        } elseif ($payload_length > 125) {
            $frame_head_binstr .= decbin(126);
            $frame_head_binstr .= sprintf('%016b', $payload_length);
        } else {
            $frame_head_binstr .= sprintf('%07b', $payload_length);
        }

        $frame = '';

        foreach (str_split($frame_head_binstr, 8) as $binstr) $frame .= chr(bindec($binstr));

        if ($masked) {
            $mask = '';
            for ($i = 0; $i < 4; $i++) $mask .= chr(rand(0, 255));
            $frame .= $mask;
        }

        for ($i = 0; $i < $payload_length; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        $this->write($frame);
    }

    public function receive()
    {
        if (!$this->is_connected) $this->connect();

        $data = $this->read(2);

        if ($data === false) {
            $this->close();
            return false;
        }

        $final = (bool) (ord($data[0]) & 1 << 7);

        $rsv1  = (bool) (ord($data[0]) & 1 << 6);
        $rsv2  = (bool) (ord($data[0]) & 1 << 5);
        $rsv3  = (bool) (ord($data[0]) & 1 << 4);

        $opcode_int = ord($data[0]) & 31;
        $opcode_ints = array_flip(self::$opcodes);
        if (!array_key_exists($opcode_int, $opcode_ints)) {
            exit("Bad opcode in websocket frame: $opcode_int");
        }
        $opcode = $opcode_ints[$opcode_int];
        $this->last_opcode = $opcode;

        $mask = (bool) (ord($data[1]) >> 7);

        $payload = "";

        $payload_length = (int) ord($data[1]) & 127;
        if ($payload_length > 125) {
            if ($payload_length === 126) $data = $this->read(2);
            else                         $data = $this->read(8);
            $payload_length = bindec(self::sprintB($data));
        }

        if ($mask) $masking_key = $this->read(4);

        if ($payload_length > 0) {
            $data = $this->read($payload_length);
            if ($mask) {
                $payload = '';
                for ($i = 0; $i < $payload_length; $i++) $payload .= ($data[$i] ^ $masking_key[$i % 4]);
            } else $payload = $data;
        }

        if ($opcode === 'close') {
            if ($payload_length >= 2) {
                $status_bin = $payload[0] . $payload[1];
                $status = bindec(sprintf("%08b%08b", ord($payload[0]), ord($payload[1])));
                $this->close_status = $status;
                $payload = substr($payload, 2);
            }

            if ($this->is_closing) $this->is_closing = false;
            else $this->send($status_bin . 'Close acknowledged: ' . $status, 'close', true);

            fclose($this->socket);
            $this->is_connected = false;
        }

        if ($opcode === 'ping') {
            $this->send('', 'pong');
        }

        return $payload;
    }

    public function close($status = 1000, $message = 'ttfn')
    {
        $status_binstr = sprintf('%016b', $status);
        $status_str = '';
        foreach (str_split($status_binstr, 8) as $binstr) $status_str .= chr(bindec($binstr));
        if (is_null($this->close_status)) $this->send($status_str . $message, 'close', true);

        $this->is_closing = true;
        $this->is_connected = false;
        if (is_null($this->close_status)) $response = $this->receive();
        else return false;

        return $response;
    }

    protected function write($data)
    {
        $written = @fwrite($this->socket, $data);

        if ($written < strlen($data)) {
            $this->close_status = "Could only write $written out of " . strlen($data) . " bytes.";
            $this->close();
        }
    }

    protected function read($length)
    {
        $data = '';
        while (strlen($data) < $length) {
            $buffer = @fread($this->socket, $length - strlen($data));
            if ($buffer === false) {
                $metadata = stream_get_meta_data($this->socket);
                $this->close_status = 'Broken frame.  Stream state: ' . json_encode($metadata);
                $this->close();
                return false;
            }
            if ($buffer === '') {
                $metadata = stream_get_meta_data($this->socket);
                $this->close_status = 'Empty read; connection dead?  Stream state: ' . json_encode($metadata);
                $this->close();
                return false;
            }
            $data .= $buffer;
        }
        return $data;
    }

    protected static function sprintB($string)
    {
        $return = '';
        for ($i = 0; $i < strlen($string); $i++) $return .= sprintf("%08b", ord($string[$i]));
        return $return;
    }

    public function connect()
    {
        $url_parts = parse_url($this->socket_uri);
        $scheme    = $url_parts['scheme'];
        $host      = $url_parts['host'];
        $user      = isset($url_parts['user']) ? $url_parts['user'] : '';
        $pass      = isset($url_parts['pass']) ? $url_parts['pass'] : '';
        $port      = isset($url_parts['port']) ? $url_parts['port'] : ($scheme === 'wss' ? 443 : 80);
        $path      = isset($url_parts['path']) ? $url_parts['path'] : '/';
        $query     = isset($url_parts['query'])    ? $url_parts['query'] : '';
        $fragment  = isset($url_parts['fragment']) ? $url_parts['fragment'] : '';

        $path_with_query = $path;
        if (!empty($query))    $path_with_query .= '?' . $query;
        if (!empty($fragment)) $path_with_query .= '#' . $fragment;

        if (!in_array($scheme, array('ws', 'wss'))) {
            exit("Url should have scheme ws or wss, not '$scheme' from URI '$this->socket_uri' .");
        }

        $host_uri = ($scheme === 'wss' ? 'ssl' : 'tcp') . '://' . $host;

        $this->socket = @fsockopen($host_uri, $port, $errno, $errstr, $this->options['timeout']);

        if ($this->socket === false) {
            $this->close_status = "Could not open socket to \"$host:$port\": $errstr ($errno).";
            $this->close();
            return false;
        }

        stream_set_timeout($this->socket, $this->options['timeout']);

        $key = self::generateKey();

        $headers = array(
            'host'                  => $host . ":" . $port,
            'user-agent'            => $this->options['user-agent'],
            'connection'            => 'Upgrade',
            'upgrade'               => 'websocket',
            'sec-websocket-key'     => $key,
            'sec-websocket-version' => '13',
        );

        if ($user || $pass) {
            $headers['authorization'] = 'Basic ' . base64_encode($user . ':' . $pass) . "\r\n";
        }

        if (isset($this->options['origin'])) $headers['origin'] = $this->options['origin'];

        if (isset($this->options['headers'])) {
            $headers = array_merge($headers, array_change_key_case($this->options['headers']));
        }

        $header =
            "GET " . $path_with_query . " HTTP/1.1\r\n"
            . implode(
                "\r\n",
                array_map(
                    function ($key, $value) {
                        return "$key: $value";
                    },
                    array_keys($headers),
                    $headers
                )
            )
            . "\r\n\r\n";

        $this->write($header);

        $response = '';
        do {
            $buffer = stream_get_line($this->socket, 1024, "\r\n");
            $response .= $buffer . "\n";
            $metadata = stream_get_meta_data($this->socket);
        } while (!feof($this->socket) && $metadata['unread_bytes'] > 0);

        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            $address = $scheme . '://' . $host . $path_with_query;
            exit("Connection to '{$address}' failed: Server sent invalid upgrade response:\n"
                . $response);
        }

        $keyAccept = trim($matches[1]);
        $expectedResonse
            = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        if ($keyAccept !== $expectedResonse) {
            exit('Server sent bad upgrade response.');
        }

        $this->is_connected = true;
    }

    protected static function generateKey()
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';
        $key = '';
        $chars_length = strlen($chars);
        for ($i = 0; $i < 16; $i++) $key .= $chars[mt_rand(0, $chars_length - 1)];
        return base64_encode($key);
    }
}
