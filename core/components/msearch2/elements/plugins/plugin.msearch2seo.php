<?php
$id = 0;

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

    case 'OnDocFormSave':
    case 'OnResourceDelete':
    case 'OnResourceUndelete':
        /* @var modResource $modResource */
        if (!empty($resource) && $resource instanceof modResource) {
            $id = $resource->get('id');
        }
        break;

    case 'OnCommentSave':
    case 'OnCommentRemove':
    case 'OnCommentDelete':
        /* @var TicketComment $TicketComment */
        if (!empty($TicketComment) && $TicketComment instanceof TicketComment) {
            $id = $TicketComment->getOne('Thread')->get('resource');
        }
        break;

}


if (!empty($id)) {
    /* @var modProcessorResponse $response */
    $response = $modx->runProcessor('mgr/index/updateseo', array('id' => $id), array('processors_path' => MODX_CORE_PATH . 'components/msearch2/processors/'));

    if ($response->isError()) {
        $modx->log(modX::LOG_LEVEL_ERROR, print_r($response->getAllErrors(), true));
    }
}