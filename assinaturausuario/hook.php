<?php

/**
 * Plugin Assinatura de Usuário - instalação (com migração da 1.x) e desinstalação
 */

function plugin_assinaturausuario_install(): bool
{
    global $DB;

    require_once __DIR__ . '/inc/config.class.php';
    require_once __DIR__ . '/inc/imagem.class.php';
    require_once __DIR__ . '/inc/assinatura.class.php';
    $opcoes = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // ------------------------------------------------------------ configurações (chave/valor)
    if (!$DB->tableExists('glpi_plugin_assinaturausuario_configs')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assinaturausuario_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext NULL,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }
    foreach (PluginAssinaturausuarioConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_assinaturausuario_configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert('glpi_plugin_assinaturausuario_configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor]);
        }
    }

    // ------------------------------------------------------------ assinaturas (uma por usuário)
    if (!$DB->tableExists('glpi_plugin_assinaturausuario_assinaturas')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assinaturausuario_assinaturas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `conteudo` longtext NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `tipos` text NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`),
            KEY `is_active` (`is_active`)
        ) $opcoes");
    }

    // ------------------------------------------------------------ imagens das assinaturas (arquivos em files/_plugins)
    if (!$DB->tableExists('glpi_plugin_assinaturausuario_imagens')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_assinaturausuario_imagens` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `token` varchar(40) NOT NULL,
            `arquivo` varchar(255) NOT NULL,
            `mime` varchar(50) NOT NULL DEFAULT '',
            `sha1sum` varchar(40) NOT NULL DEFAULT '',
            `largura` int unsigned NOT NULL DEFAULT 0,
            `altura` int unsigned NOT NULL DEFAULT 0,
            `tamanho` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token` (`token`),
            KEY `usuario_hash` (`users_id`, `sha1sum`)
        ) $opcoes");
    }
    $pasta = PluginAssinaturausuarioImagem::pasta();
    if (!is_dir($pasta)) {
        mkdir($pasta, 0755, true);
    }
    if (!is_file($pasta . '/.htaccess')) {
        file_put_contents($pasta . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
    }

    // ------------------------------------------------------------ 1.x: assinaturas na tabela antiga (imagens viram arquivos)
    if ($DB->tableExists('glpi_plugin_assinaturausuario_signatures') && PluginAssinaturausuarioConfig::getConfig('migrado_1x') !== '1') {
        foreach ($DB->request(['FROM' => 'glpi_plugin_assinaturausuario_signatures']) as $r) {
            $uid = (int) $r['users_id'];
            if ($uid <= 0 || count($DB->request(['FROM' => 'glpi_plugin_assinaturausuario_assinaturas', 'WHERE' => ['users_id' => $uid], 'LIMIT' => 1])) > 0) {
                continue;
            }
            $html = PluginAssinaturausuarioAssinatura::normalizar((string) $r['signature'], $uid, [], true);
            $DB->insert('glpi_plugin_assinaturausuario_assinaturas', ['users_id' => $uid, 'conteudo' => $html, 'is_active' => 1]);
        }
        PluginAssinaturausuarioConfig::setConfig('migrado_1x', '1');
    }

    return true;
}

function plugin_assinaturausuario_uninstall(): bool
{
    // Regra do projeto: tabelas e imagens ficam (reinstalar recupera tudo e as assinaturas já
    // gravadas nos acompanhamentos continuam exibindo as imagens)
    return true;
}
