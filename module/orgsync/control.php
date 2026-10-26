<?php
/**
 * The control file of orgsync module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2025 禅道软件（青岛）有限公司(ZenTao Software (Qingdao) Co., Ltd. www.cnezsoft.com)
 * @license     ZPL(http://zpl.pub/page/zplv12.html) or AGPL(https://www.gnu.org/licenses/agpl-3.0.en.html)
 * @package     orgsync
 * @link        https://www.zentao.net
 */
class orgsync extends control
{
    /**
     * 配置页。
     * Config page.
     *
     * @access public
     * @return void
     */
    public function index()
    {
        $this->app->loadLang('orgsync');
        if(!$this->checkAdmin()) helper::end($this->lang->orgsync->error->adminOnly);

        $model = $this->loadModel('orgsync');
        if($_POST)
        {
            $current  = $model->getSettings();
            $settings = array
            (
                'appKey'       => trim((string)$this->post->appKey),
                'appSecret'    => trim((string)$this->post->appSecret),
                'agentId'      => trim((string)$this->post->agentId),
                'parentDept'   => (int)$this->post->parentDept,
                'initPassword' => trim((string)$this->post->initPassword)
            );

            if($settings['appSecret'] === '')    $settings['appSecret'] = $current->appSecret;
            if($settings['initPassword'] === '') $settings['initPassword'] = $current->initPassword;

            if(empty($settings['appKey']) || empty($settings['appSecret'])) return $this->send(array('result' => 'fail', 'message' => $this->lang->orgsync->error->noCredential));
            if(strlen($settings['initPassword']) < 6) return $this->send(array('result' => 'fail', 'message' => $this->lang->orgsync->error->password));

            $model->saveSettings($settings);
            if(dao::isError()) return $this->send(array('result' => 'fail', 'message' => dao::getError()));

            return $this->send(array('result' => 'success', 'message' => $this->lang->saveSuccess, 'load' => true));
        }

        $this->view->title    = $this->lang->orgsync->common;
        $this->view->settings = $model->getSettings();
        $this->view->depts    = $this->loadModel('dept')->getOptionMenu();
        $this->view->lastSync = $model->getLastSync();
        $this->display();
    }

    /**
     * 执行同步。
     * Run sync.
     *
     * @access public
     * @return void
     */
    public function sync()
    {
        $this->app->loadLang('orgsync');
        if(!$this->checkAdmin()) return $this->send(array('result' => 'fail', 'message' => $this->lang->orgsync->error->adminOnly));
        if($this->server->request_method != 'POST') return $this->send(array('result' => 'fail', 'message' => $this->lang->orgsync->error->postOnly));

        return $this->send($this->orgsyncZen->sync());
    }

    /**
     * 是否管理员。
     * Check current user is admin.
     *
     * @access private
     * @return bool
     */
    private function checkAdmin(): bool
    {
        if(!empty($this->app->user->admin)) return true;
        if(empty($this->app->company)) return false;

        return strpos((string)$this->app->company->admins, ",{$this->app->user->account},") !== false;
    }
}
