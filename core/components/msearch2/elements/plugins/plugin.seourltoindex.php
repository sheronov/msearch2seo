<?php

switch ($modx->event->name) {
    case 'sfOnUrlAdd':
    case 'sfOnUrlUpdate':
    case 'sfOnUrlBeforeRemove':
        /* @var sfUrls $object */
        /* @var modProcessorResponse $response */

        if ($modx->event->name === 'sfOnUrlBeforeRemove') {
            $object->set('active', false);
            $object->save();
        }

        $response = $modx->runProcessor('mgr/index/updateseo', ['seo_id' => $object->get('id')],
            ['processors_path' => MODX_CORE_PATH.'components/msearch2/processors/']);

        if ($response->isError()) {
            $modx->log(modX::LOG_LEVEL_ERROR, print_r($response->getAllErrors(), true));
        }
        break;
}
