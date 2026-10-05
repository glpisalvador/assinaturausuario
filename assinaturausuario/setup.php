<?php

/**
 * Plugin Assinatura de Usuário - GLPI 11 e 12
 * Cada pessoa cadastra a própria assinatura (texto rico com imagens e variáveis) e ela é
 * adicionada uma única vez aos acompanhamentos, soluções e tarefas enviados pelos formulários
 * nativos da linha do tempo. Edições, outros plugins, API, coletor de e-mail e tarefas
 * automáticas não recebem assinatura.
 */

define('PLUGIN_ASSINATURAUSUARIO_VERSION', '2.0.0');
define('PLUGIN_ASSINATURAUSUARIO_MIN_GLPI', '11.0.0');
define('PLUGIN_ASSINATURAUSUARIO_MAX_GLPI', '12.99.99');

function plugin_init_assinaturausuario(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['assinaturausuario'] = true;

    // Imagens da assinatura: abertas sem login (token aleatório), para aparecerem em qualquer
    // chamado e nos e-mails de notificação
    $publico = '#^/front/imagem\.php$#';
    if (class_exists('\Glpi\Http\Firewall')) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('assinaturausuario', $publico, \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
    }
    if (class_exists('\Glpi\Http\SessionManager')) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath('assinaturausuario', $publico);
    }

    $plugin = new Plugin();
    if (!$plugin->isActivated('assinaturausuario')) {
        return;
    }

    Plugin::registerClass('PluginAssinaturausuarioAssinatura');
    Plugin::registerClass('PluginAssinaturausuarioPreferencia', ['addtabon' => ['Preference', 'User']]);

    $PLUGIN_HOOKS['config_page']['assinaturausuario'] = 'front/config.form.php';

    $assinar = ['PluginAssinaturausuarioAssinatura', 'aoAdicionar'];
    $PLUGIN_HOOKS['pre_item_add']['assinaturausuario'] = array_fill_keys(PluginAssinaturausuarioConfig::classesAssinaveis(), $assinar);
    $PLUGIN_HOOKS['post_item_form']['assinaturausuario'] = ['PluginAssinaturausuarioAssinatura', 'aoExibirFormulario'];

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['add_css']['assinaturausuario'] = ['css/assinaturausuario.css'];
        $PLUGIN_HOOKS['add_javascript']['assinaturausuario'] = ['js/assinaturausuario.js'];
    }
}

function plugin_version_assinaturausuario(): array
{
    return [
        'name'         => 'Assinatura de Usuário',
        'version'      => PLUGIN_ASSINATURAUSUARIO_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ASSINATURAUSUARIO_MIN_GLPI,
                'max' => PLUGIN_ASSINATURAUSUARIO_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_assinaturausuario_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_ASSINATURAUSUARIO_MIN_GLPI, '>=');
}

function plugin_assinaturausuario_check_config($verbose = false): bool
{
    return true;
}
