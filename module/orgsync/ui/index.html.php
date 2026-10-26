<?php
declare(strict_types=1);
/**
 * The index view file of orgsync module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2025 禅道软件（青岛）有限公司(ZenTao Software (Qingdao) Co., Ltd. www.cnezsoft.com)
 * @license     ZPL(http://zpl.pub/page/zplv12.html) or AGPL(https://www.gnu.org/licenses/agpl-3.0.en.html)
 * @package     orgsync
 * @link        https://www.zentao.net
 */
namespace zin;

jsVar('orgsyncLang', $lang->orgsync);

$secretPlaceholder = !empty($settings->appSecret) ? $lang->orgsync->secretSaved : '';

formPanel
(
    setID('orgsyncForm'),
    set::title($lang->orgsync->ding),
    to::headingActions
    (
        a
        (
            setID('orgsyncSyncBtn'),
            setClass('btn primary'),
            on::click('syncOrgSync()'),
            icon('refresh'),
            $lang->orgsync->syncNow
        )
    ),
    formRow
    (
        formGroup
        (
            set::label($lang->orgsync->appKey),
            input
            (
                set::name('appKey'),
                set::value($settings->appKey),
                set::placeholder('ding')
            )
        )
    ),
    formRow
    (
        formGroup
        (
            set::label($lang->orgsync->appSecret),
            input
            (
                set::name('appSecret'),
                set::type('password'),
                set::placeholder($secretPlaceholder)
            )
        )
    ),
    formRow
    (
        formGroup
        (
            set::label($lang->orgsync->agentId),
            input
            (
                set::name('agentId'),
                set::value($settings->agentId),
                set::placeholder($lang->orgsync->agentIdTip)
            )
        )
    ),
    formRow
    (
        formGroup
        (
            set::label($lang->orgsync->parentDept),
            picker
            (
                set::name('parentDept'),
                set::items($depts),
                set::value((string)$settings->parentDept)
            )
        )
    ),
    formRow
    (
        formGroup
        (
            set::label($lang->orgsync->initPassword),
            input
            (
                set::name('initPassword'),
                set::value($settings->initPassword)
            ),
            p
            (
                setClass('text-gray mt-1'),
                $lang->orgsync->initPasswordTip
            )
        )
    ),
    formRow
    (
        formGroup
        (
            set::label($lang->orgsync->tipLabel),
            p
            (
                setClass('text-gray py-2 leading-6'),
                $lang->orgsync->tip
            )
        )
    )
);

if(empty($lastSync))
{
    $resultContent = div(setClass('text-gray'), $lang->orgsync->neverSync);
}
else
{
    $lines   = array();
    $lines[] = sprintf($lang->orgsync->lastSyncTime, $lastSync->time);
    $lines[] = sprintf
    (
        $lang->orgsync->syncResult,
        (int)zget($lastSync, 'deptCreated', 0),
        (int)zget($lastSync, 'deptUpdated', 0),
        (int)zget($lastSync, 'userCreated', 0),
        (int)zget($lastSync, 'userUpdated', 0),
        (int)zget($lastSync, 'userSkipped', 0)
    );

    $children = array(div(setClass('text-gray'), implode(' ', $lines)));
    if(!empty($lastSync->errors))
    {
        $errorItems = array();
        foreach($lastSync->errors as $error) $errorItems[] = li(setClass('text-warning'), $error);

        $children[] = div(setClass('mt-2 text-warning'), $lang->orgsync->errors);
        $children[] = ul($errorItems);
    }

    $resultContent = div($children);
}

panel
(
    setClass('mt-4'),
    set::title($lang->orgsync->lastResult),
    div(setID('orgsyncResult'), $resultContent)
);

render();
