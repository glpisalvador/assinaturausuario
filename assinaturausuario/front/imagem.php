<?php

/**
 * Plugin Assinatura de Usuário - entrega das imagens da assinatura (sem login, por token).
 * Liberada no setup.php (Firewall sem verificação e sem sessão): as imagens aparecem em
 * qualquer chamado e nos e-mails de notificação.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
PluginAssinaturausuarioImagem::enviar((string) ($_GET['t'] ?? ''));
exit;
