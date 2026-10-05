<?php

/**
 * Plugin Assinatura de Usuário - imagens da assinatura.
 * Ficam em files/_plugins/assinaturausuario (fora do acesso direto da web), reduzidas para a
 * largura máxima configurada, e são entregues por front/imagem.php através de um token aleatório.
 */
class PluginAssinaturausuarioImagem
{
    public const TABELA = 'glpi_plugin_assinaturausuario_imagens';
    public const MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    public const LIMITE_ENTRADA = 8 * 1024 * 1024;

    public static function pasta(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/assinaturausuario';
    }

    /** Caminho relativo (/plugins/...) da imagem; com $absoluta, prefixado pela URL do GLPI */
    public static function url(string $token, bool $absoluta = false): string
    {
        global $CFG_GLPI;
        $caminho = '/plugins/assinaturausuario/front/imagem.php?t=' . $token;
        $base = trim((string) ($CFG_GLPI['url_base'] ?? ''));
        return ($absoluta && $base !== '' ? rtrim($base, '/') : (string) $CFG_GLPI['root_doc']) . $caminho;
    }

    /** Token das imagens do plugin presentes num HTML */
    public static function tokensNoHtml(string $html): array
    {
        preg_match_all('#/plugins/assinaturausuario/front/imagem\.php\?t=([a-f0-9]{40})#', $html, $m);
        return array_values(array_unique($m[1]));
    }

    public static function porToken(string $token): ?array
    {
        global $DB;
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            return null;
        }
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['token' => $token], 'LIMIT' => 1]) as $r) {
            return $r;
        }
        return null;
    }

    public static function caminho(array $imagem): string
    {
        return self::pasta() . '/' . basename((string) $imagem['arquivo']);
    }

    /** Imagem embutida (data URI) a partir do token */
    public static function dataUri(string $token): ?string
    {
        $img = self::porToken($token);
        if ($img === null || !is_file(self::caminho($img))) {
            return null;
        }
        return 'data:' . $img['mime'] . ';base64,' . base64_encode((string) file_get_contents(self::caminho($img)));
    }

    /**
     * Guarda uma imagem (bytes) para o usuário: valida o tipo, reduz para a largura máxima e
     * reaproveita a mesma imagem se ela já existir. Retorna [token, largura, altura] ou null.
     */
    public static function guardar(string $bytes, int $usuario): ?array
    {
        global $DB;
        if ($bytes === '' || strlen($bytes) > self::LIMITE_ENTRADA) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!isset(self::MIMES[$mime])) {
            return null;
        }
        [$largura, $altura] = [(int) $info[0], (int) $info[1]];
        if ($largura <= 0 || $altura <= 0) {
            return null;
        }
        $maxima = PluginAssinaturausuarioConfig::largura();
        if (function_exists('imagecreatefromstring') && ($largura > $maxima || $mime === 'image/webp')) {
            $origem = @imagecreatefromstring($bytes);
            if ($origem !== false) {
                $novaLargura = min($largura, $maxima);
                $novaAltura = max(1, (int) round($altura * $novaLargura / $largura));
                $destino = imagecreatetruecolor($novaLargura, $novaAltura);
                $transparente = $mime !== 'image/jpeg';
                if ($transparente) {
                    imagealphablending($destino, false);
                    imagesavealpha($destino, true);
                    imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
                }
                imagecopyresampled($destino, $origem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);
                ob_start();
                if ($transparente) {
                    imagepng($destino, null, 6);
                    $mime = 'image/png';
                } else {
                    imagejpeg($destino, null, 88);
                }
                $bytes = (string) ob_get_clean();
                imagedestroy($origem);
                imagedestroy($destino);
                [$largura, $altura] = [$novaLargura, $novaAltura];
            }
        }
        $hash = sha1($bytes);
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['users_id' => $usuario, 'sha1sum' => $hash], 'LIMIT' => 1]) as $r) {
            if (is_file(self::caminho($r))) {
                return [(string) $r['token'], (int) $r['largura'], (int) $r['altura']];
            }
        }
        $pasta = self::pasta();
        if (!is_dir($pasta) && !mkdir($pasta, 0755, true) && !is_dir($pasta)) {
            return null;
        }
        $token = bin2hex(random_bytes(20));
        $arquivo = $token . '.' . self::MIMES[$mime];
        if (file_put_contents($pasta . '/' . $arquivo, $bytes) === false) {
            return null;
        }
        $DB->insert(self::TABELA, [
            'users_id' => $usuario,
            'token'    => $token,
            'arquivo'  => $arquivo,
            'mime'     => $mime,
            'sha1sum'  => $hash,
            'largura'  => $largura,
            'altura'   => $altura,
            'tamanho'  => strlen($bytes),
        ]);
        return [$token, $largura, $altura];
    }

    /** Bytes de um documento do GLPI (assinaturas antigas apontavam para document.send.php) */
    public static function bytesDocumento(int $id, bool $verificar = true): ?string
    {
        $doc = new Document();
        if ($id <= 0 || !$doc->getFromDB($id) || !str_starts_with((string) $doc->fields['mime'], 'image/')) {
            return null;
        }
        if ($verificar && !$doc->canViewFile()) {
            return null;
        }
        $arquivo = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        return is_file($arquivo) ? (string) file_get_contents($arquivo) : null;
    }

    /** Envia a imagem ao navegador (front/imagem.php) */
    public static function enviar(string $token): void
    {
        $img = self::porToken($token);
        $caminho = $img !== null ? self::caminho($img) : '';
        if ($img === null || !is_file($caminho)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Imagem não encontrada.';
            return;
        }
        $etag = '"' . $img['sha1sum'] . '"';
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . $img['mime']);
        header('Content-Length: ' . filesize($caminho));
        header('Content-Disposition: inline; filename="assinatura.' . (self::MIMES[$img['mime']] ?? 'png') . '"');
        readfile($caminho);
    }

    /** Total de imagens e espaço ocupado */
    public static function resumo(): array
    {
        global $DB;
        $r = ['total' => 0, 'bytes' => 0];
        foreach ($DB->request(['SELECT' => [new \Glpi\DBAL\QueryExpression('COUNT(*) AS total'), new \Glpi\DBAL\QueryExpression('COALESCE(SUM(tamanho), 0) AS bytes')], 'FROM' => self::TABELA]) as $l) {
            $r = ['total' => (int) $l['total'], 'bytes' => (int) $l['bytes']];
        }
        return $r;
    }
}
