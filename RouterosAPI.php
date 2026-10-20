<?php
/**
 * RouterOS API client (port 8728) — implementasi minimal & mandiri.
 * Protokol: length-prefixed words + sentences, diakhiri word kosong.
 */
class RouterosAPI
{
    /** @var resource|null */
    private $socket;

    public function connect(string $host, string $user, string $pass, int $port = 8728, int $timeout = 10): bool
    {
        $this->socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$this->socket) {
            throw new RuntimeException("Koneksi MikroTik gagal ($host:$port): $errstr");
        }
        stream_set_timeout($this->socket, $timeout);

        // Coba login legacy (plaintext) dulu.
        $this->writeSentence(['/login', "=name=$user", "=password=$pass"]);
        $resp = $this->readSentence();
        if ($resp === false) {
            return false;
        }
        if (isset($resp[0]) && $resp[0] === '!done') {
            return true;
        }

        // Login challenge-response (RouterOS >= 6.43).
        $ret = null;
        foreach ($resp as $word) {
            if (strpos($word, '=ret=') === 0) {
                $ret = substr($word, 5);
                break;
            }
        }
        if ($ret !== null) {
            $hash = md5(chr(0) . $pass . pack('H*', $ret));
            $this->writeSentence(['/login', "=name=$user", "=response=00$hash"]);
            $resp2 = $this->readSentence();
            return $resp2 !== false && isset($resp2[0]) && $resp2[0] === '!done';
        }

        return false;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    /** Kirim perintah, kembalikan array kalimat (array of words). */
    public function comm(string $command, array $attrs = []): array
    {
        $words = array_merge([$command], $attrs);
        $this->writeSentence($words);

        $sentences = [];
        while (true) {
            $resp = $this->readSentence();
            if ($resp === false) {
                break;
            }
            $sentences[] = $resp;
            $first = $resp[0] ?? '';
            if ($first === '!done' || $first === '!trap' || $first === '!fatal') {
                break;
            }
        }
        return $sentences;
    }

    /** Ambil semua simple queue (id, name, target, max-limit). */
    public function getSimpleQueues(): array
    {
        $resp = $this->comm('/queue/simple/print', ['=.proplist=.id,name,target,max-limit']);
        $queues = [];
        foreach ($resp as $sentence) {
            if (($sentence[0] ?? '') !== '!re') {
                continue;
            }
            $q = [];
            foreach ($sentence as $word) {
                if ($word === '' || $word[0] !== '=') {
                    continue;
                }
                [$k, $v] = explode('=', substr($word, 1), 2);
                if ($k === '.id') {
                    $k = 'id';
                } elseif ($k === 'max-limit') {
                    $k = 'max_limit';
                }
                $q[$k] = $v;
            }
            $queues[] = $q;
        }
        return $queues;
    }

    /** Ubah max-limit sebuah queue berdasarkan .id. */
    public function setMaxLimit(string $id, string $maxLimit): void
    {
        $this->comm('/queue/simple/set', ["=.id=$id", "=max-limit=$maxLimit"]);
    }

    private function writeSentence(array $words): void
    {
        $out = '';
        foreach ($words as $w) {
            $out .= $this->writeWord($w);
        }
        $out .= chr(0); // penanda akhir kalimat
        fwrite($this->socket, $out);
    }

    private function writeWord(string $w): string
    {
        $len = strlen($w);
        if ($len < 0x80) {
            return chr($len) . $w;
        }
        if ($len < 0x4000) {
            $len |= 0x8000;
            return chr(($len >> 8) & 0xFF) . chr($len & 0xFF) . $w;
        }
        if ($len < 0x200000) {
            $len |= 0xC00000;
            return chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF) . $w;
        }
        if ($len < 0x10000000) {
            $len |= 0xE0000000;
            return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF) . $w;
        }
        return chr(0xF0) . pack('N', $len) . $w;
    }

    /** @return array|false */
    private function readSentence()
    {
        $words = [];
        while (true) {
            $w = $this->readWord();
            if ($w === false) {
                return false;
            }
            if ($w === '') {
                break;
            }
            $words[] = $w;
        }
        return $words;
    }

    /** @return string|false */
    private function readWord()
    {
        $b = $this->readBytes(1);
        if ($b === false) {
            return false;
        }
        $byte = ord($b);
        $len = 0;

        if (($byte & 0x80) === 0) {
            $len = $byte;
        } elseif (($byte & 0xC0) === 0x80) {
            $len = ($byte & 0x3F) << 8;
            $len += ord($this->readBytes(1));
        } elseif (($byte & 0xE0) === 0xC0) {
            $len = ($byte & 0x1F) << 16;
            $len += ord($this->readBytes(1)) << 8;
            $len += ord($this->readBytes(1));
        } elseif (($byte & 0xF0) === 0xE0) {
            $len = ($byte & 0x0F) << 24;
            $len += ord($this->readBytes(1)) << 16;
            $len += ord($this->readBytes(1)) << 8;
            $len += ord($this->readBytes(1));
        } else {
            $len = ord($this->readBytes(1)) << 24;
            $len += ord($this->readBytes(1)) << 16;
            $len += ord($this->readBytes(1)) << 8;
            $len += ord($this->readBytes(1));
        }

        return $this->readBytes($len);
    }

    /** @return string|false */
    private function readBytes(int $n)
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->socket, $n - strlen($data));
            if ($chunk === false || $chunk === '') {
                return false;
            }
            $data .= $chunk;
        }
        return $data;
    }
}
