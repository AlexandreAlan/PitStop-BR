<?php
declare(strict_types=1);

/**
 * Cliente SMTP mínimo (sem dependências externas), suporta TLS implícito
 * (porta 465) ou STARTTLS (porta 587), com AUTH LOGIN.
 */
function enviarEmail(string $paraEmail, string $assunto, string $corpoHtml): bool
{
    $paraEmail = trim($paraEmail);
    if (!filter_var($paraEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('[PitStop BR] Tentativa de envio de e-mail pra endereço inválido.');
        return false;
    }

    $host   = (string) (getenv('SMTP_HOST') ?: '');
    $port   = (int) (getenv('SMTP_PORT') ?: 465);
    $secure = (string) (getenv('SMTP_SECURE') ?: 'true') === 'true';
    $user   = (string) (getenv('SMTP_USER') ?: '');
    $pass   = (string) (getenv('SMTP_PASS') ?: '');
    $from   = (string) (getenv('SMTP_FROM') ?: $user);

    if ($host === '' || $user === '' || $pass === '') {
        error_log('[PitStop BR] SMTP não configurado — e-mail não enviado.');
        return false;
    }

    $enderecoRemoto = ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($enderecoRemoto, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        error_log("[PitStop BR] Falha ao conectar no SMTP ($host:$port): $errstr");
        return false;
    }
    stream_set_timeout($fp, 10);

    try {
        $ler = static function () use ($fp): string {
            $resposta = '';
            while (($linha = fgets($fp, 515)) !== false) {
                $resposta .= $linha;
                if (strlen($linha) < 4 || $linha[3] === ' ') {
                    break;
                }
            }
            return $resposta;
        };
        $escrever = static function (string $comando) use ($fp): void {
            fwrite($fp, $comando . "\r\n");
        };
        $esperar = static function (string $codigoEsperado) use ($ler): string {
            $resposta = $ler();
            if (!str_starts_with($resposta, $codigoEsperado)) {
                throw new RuntimeException("Resposta SMTP inesperada (esperado $codigoEsperado): $resposta");
            }
            return $resposta;
        };

        $esperar('220');
        $escrever('EHLO ' . $host);
        $esperar('250');

        if (!$secure) {
            $escrever('STARTTLS');
            $esperar('220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Falha ao negociar TLS via STARTTLS.');
            }
            $escrever('EHLO ' . $host);
            $esperar('250');
        }

        $escrever('AUTH LOGIN');
        $esperar('334');
        $escrever(base64_encode($user));
        $esperar('334');
        $escrever(base64_encode($pass));
        $esperar('235');

        $escrever('MAIL FROM:<' . smtpExtrairEndereco($from) . '>');
        $esperar('250');
        $escrever('RCPT TO:<' . $paraEmail . '>');
        $esperar('250');
        $escrever('DATA');
        $esperar('354');

        $messageId = bin2hex(random_bytes(16)) . '@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);
        $assuntoCodificado = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $assunto)) . '?=';
        $cabecalhos = [
            'From: ' . $from,
            'To: <' . $paraEmail . '>',
            'Subject: ' . $assuntoCodificado,
            'Date: ' . (new DateTime())->format(DateTime::RFC2822),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // "Dot-stuffing": linhas que começam com "." precisam virar ".." (regra do protocolo SMTP)
        $corpoEscapado = preg_replace('/^\./m', '..', $corpoHtml);
        $escrever(implode("\r\n", $cabecalhos) . "\r\n\r\n" . $corpoEscapado . "\r\n.");
        $esperar('250');

        $escrever('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('[PitStop BR] Erro ao enviar e-mail via SMTP: ' . $e->getMessage());
        if (is_resource($fp)) {
            fclose($fp);
        }
        return false;
    }
}

function smtpExtrairEndereco(string $from): string
{
    if (preg_match('/<([^>]+)>/', $from, $m)) {
        return $m[1];
    }
    return $from;
}
