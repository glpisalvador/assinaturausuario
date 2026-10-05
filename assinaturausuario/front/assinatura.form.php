<?php

/**
 * Plugin Assinatura de Usuário - grava ou remove a assinatura (aba das Preferências e do usuário)
 */

Session::checkLoginUser();

$C = PluginAssinaturausuarioConfig::class;
$A = PluginAssinaturausuarioAssinatura::class;
$usuario = (int) ($_POST['users_id'] ?? 0);

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !PluginAssinaturausuarioPreferencia::podeEditar($usuario)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$uploads = $C::uploadsPost('assinatura');
if (!empty($_POST['remover'])) {
    $A::remover($usuario);
    Session::addMessageAfterRedirect('Assinatura removida.', false, INFO);
} else {
    $enviado = (string) ($_POST['assinatura'] ?? '');
    $html = $A::normalizar($enviado, $usuario, $uploads);
    if ($A::vazio($html)) {
        $A::remover($usuario);
        Session::addMessageAfterRedirect('O editor estava vazio: a assinatura foi removida.', false, WARNING);
    } else {
        $tipos = array_filter(array_map('strval', (array) ($_POST['tipos'] ?? [])));
        $A::salvar($usuario, $html, !empty($_POST['is_active']), $tipos);
        $perdidas = substr_count(strtolower($enviado), '<img') - substr_count(strtolower($html), '<img');
        Session::addMessageAfterRedirect('Assinatura salva.', false, INFO);
        if ($perdidas > 0) {
            Session::addMessageAfterRedirect($perdidas . ' imagem(ns) não puderam ser lidas (formato não aceito, acima de 8 MB ou envio incompleto) e foram retiradas. Use PNG, JPG, GIF ou WEBP.', false, WARNING);
        }
    }
}
foreach ($uploads as $arquivo) {
    @unlink($arquivo);
}
Html::back();
