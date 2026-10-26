<?php
/**
 * The model file of orgsync module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2025 禅道软件（青岛）有限公司(ZenTao Software (Qingdao) Co., Ltd. www.cnezsoft.com)
 * @license     ZPL(http://zpl.pub/page/zplv12.html) or AGPL(https://www.gnu.org/licenses/agpl-3.0.en.html)
 * @package     orgsync
 * @link        https://www.zentao.net
 */
class orgsyncModel extends model
{
    /**
     * 获取钉钉同步配置。
     * Get sync settings.
     *
     * @access public
     * @return object
     */
    public function getSettings(): object
    {
        $saved = json_decode((string)$this->loadModel('setting')->getItem('owner=system&module=orgsync&key=ding'));

        $settings = new stdclass();
        $settings->appKey       = isset($saved->appKey) ? (string)$saved->appKey : '';
        $settings->appSecret    = isset($saved->appSecret) ? (string)$saved->appSecret : '';
        $settings->agentId      = isset($saved->agentId) ? (string)$saved->agentId : '';
        $settings->parentDept   = isset($saved->parentDept) ? (int)$saved->parentDept : 0;
        $settings->initPassword = !empty($saved->initPassword) ? (string)$saved->initPassword : $this->config->orgsync->defaultPassword;

        return $settings;
    }

    /**
     * 保存钉钉同步配置。
     * Save sync settings.
     *
     * @param  array    $settings
     * @access public
     * @return bool
     */
    public function saveSettings(array $settings): bool
    {
        return $this->loadModel('setting')->setItem('system.orgsync.ding', json_encode($settings));
    }

    /**
     * 获取外部 ID 与本地 ID 的映射。
     * Get id map.
     *
     * @param  string $type dept|user
     * @access public
     * @return array
     */
    public function getMap(string $type): array
    {
        $map = json_decode((string)$this->loadModel('setting')->getItem("owner=system&module=orgsync&key={$type}map"), true);
        return is_array($map) ? $map : array();
    }

    /**
     * 保存外部 ID 与本地 ID 的映射。
     * Save id map.
     *
     * @param  string $type
     * @param  array  $map
     * @access public
     * @return bool
     */
    public function saveMap(string $type, array $map): bool
    {
        return $this->loadModel('setting')->setItem("system.orgsync.{$type}map", json_encode($map));
    }

    /**
     * 获取上次同步结果。
     * Get last sync result.
     *
     * @access public
     * @return object|false
     */
    public function getLastSync(): object|false
    {
        $last = json_decode((string)$this->loadModel('setting')->getItem('owner=system&module=orgsync&key=last'));
        return is_object($last) ? $last : false;
    }

    /**
     * 保存本次同步结果。
     * Save last sync result.
     *
     * @param  array    $result
     * @access public
     * @return bool
     */
    public function saveLastSync(array $result): bool
    {
        $result['time'] = date('Y-m-d H:i:s');
        return $this->loadModel('setting')->setItem('system.orgsync.last', json_encode($result));
    }

    /**
     * 获取本地部门，按 ID 索引。
     * Get local depts indexed by id.
     *
     * @access public
     * @return array
     */
    public function getLocalDepts(): array
    {
        return $this->dao->select('id,parent,name,grade,`order`')->from(TABLE_DEPT)->fetchAll('id');
    }

    /**
     * 创建部门。
     * Create a dept.
     *
     * @param  string $name
     * @param  int    $parentID
     * @access public
     * @return int
     */
    public function createDept(string $name, int $parentID): int
    {
        $maxOrder = (int)$this->dao->select('MAX(`order`) AS maxOrder')->from(TABLE_DEPT)->where('parent')->eq($parentID)->fetch('maxOrder');

        $dept = new stdclass();
        $dept->name   = $name;
        $dept->parent = $parentID;
        $dept->order  = $maxOrder + 1;

        $this->dao->insert(TABLE_DEPT)->data($dept)->exec();
        if(dao::isError()) return 0;

        return (int)$this->dao->lastInsertID();
    }

    /**
     * 更新部门名称和上级部门。
     * Update dept.
     *
     * @param  int    $deptID
     * @param  string $name
     * @param  int    $parentID
     * @access public
     * @return bool
     */
    public function updateDept(int $deptID, string $name, int $parentID): bool
    {
        $this->dao->update(TABLE_DEPT)->data(array('name' => $name, 'parent' => $parentID))->where('id')->eq($deptID)->exec();
        return !dao::isError();
    }

    /**
     * 获取本地用户，按小写账号索引。
     * Get local users indexed by lower case account.
     *
     * @access public
     * @return array
     */
    public function getLocalUsers(): array
    {
        $users = $this->dao->select('id,account,realname,mobile,email,dept')->from(TABLE_USER)->where('deleted')->eq('0')->fetchAll('account');
        $indexed = array();
        foreach($users as $user) $indexed[strtolower($user->account)] = $user;
        return $indexed;
    }

    /**
     * 获取全部账号（含已删除），用于生成不冲突的账号。
     * Get all accounts including deleted ones.
     *
     * @access public
     * @return array
     */
    public function getTakenAccounts(): array
    {
        $accounts = $this->dao->select('account')->from(TABLE_USER)->fetchPairs();
        $taken    = array();
        foreach($accounts as $account) $taken[strtolower($account)] = true;

        return $taken;
    }

    /**
     * 根据邮箱查找账号。
     * Get account by email.
     *
     * @param  string $email
     * @access public
     * @return string
     */
    public function getAccountByEmail(string $email): string
    {
        if(empty($email)) return '';
        return (string)$this->dao->select('account')->from(TABLE_USER)->where('deleted')->eq('0')->andWhere('email')->eq($email)->fetch('account');
    }

    /**
     * 创建用户。
     * Create a user.
     *
     * @param  array    $user
     * @access public
     * @return int
     */
    public function createUser(array $user): int
    {
        $companyID = 1;
        if(!empty($this->app->company) && !empty($this->app->company->id)) $companyID = (int)$this->app->company->id;

        $data = new stdclass();
        $data->company  = $companyID;
        $data->account  = $user['account'];
        $data->realname = $user['realname'];
        $data->password = md5($user['password']);
        $data->dept     = (int)$user['dept'];
        $data->mobile   = (string)$user['mobile'];
        $data->email    = (string)$user['email'];
        $data->gender   = 'f';
        $data->visions  = 'rnd,lite';

        $this->dao->insert(TABLE_USER)->data($data)->exec();
        if(dao::isError()) return 0;

        return (int)$this->dao->lastInsertID();
    }

    /**
     * 更新用户的基础信息。
     * Update user basic fields.
     *
     * @param  int    $userID
     * @param  array  $fields
     * @access public
     * @return bool
     */
    public function updateUser(int $userID, array $fields): bool
    {
        $this->dao->update(TABLE_USER)->data($fields)->where('id')->eq($userID)->exec();
        return !dao::isError();
    }
}
