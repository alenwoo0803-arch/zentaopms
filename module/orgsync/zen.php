<?php
/**
 * The zen file of orgsync module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2025 禅道软件（青岛）有限公司(ZenTao Software (Qingdao) Co., Ltd. www.cnezsoft.com)
 * @license     ZPL(http://zpl.pub/page/zplv12.html) or AGPL(https://www.gnu.org/licenses/agpl-3.0.en.html)
 * @package     orgsync
 * @link        https://www.zentao.net
 */
class orgsyncZen extends orgsync
{
    /**
     * 从钉钉同步部门和人员。
     * Sync depts and users from DingTalk.
     *
     * @param  object|null $provider
     * @access public
     * @return array
     */
    public function sync(?object $provider = null): array
    {
        set_time_limit(0);
        if(session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $this->app->loadLang('orgsync');

        $model    = $this->loadModel('orgsync');
        $settings = $model->getSettings();

        if(empty($settings->appKey) || empty($settings->appSecret)) return array('result' => 'fail', 'message' => $this->lang->orgsync->error->noCredential);

        try
        {
            if($provider === null)
            {
                $this->app->loadClass('dingapi', true);
                $provider = new dingapi($settings->appKey, $settings->appSecret, $settings->agentId);
                if(!$provider->getToken()) return array('result' => 'fail', 'message' => $this->lang->orgsync->error->authFailed);
            }

            $treeResult = $provider->getDeptTree();
            if(!is_array($treeResult) || !isset($treeResult['result'])) return array('result' => 'fail', 'message' => sprintf($this->lang->orgsync->error->fetchFailed, 'department/list'));
            if($treeResult['result'] == 'selected') return array('result' => 'fail', 'message' => $this->lang->orgsync->error->scopeLimited);
            if($treeResult['result'] != 'success' || empty($treeResult['data'])) return array('result' => 'fail', 'message' => $this->lang->orgsync->error->noDept);

            $stats = array
            (
                'deptCreated' => 0,
                'deptUpdated' => 0,
                'deptSkipped' => 0,
                'userCreated' => 0,
                'userUpdated' => 0,
                'userSkipped' => 0,
                'errors'      => array()
            );

            $deptMap = $model->getMap('dept');
            $userMap = $model->getMap('user');

            $this->syncDepts($settings, $treeResult['data'], $deptMap, $stats);
            $this->loadModel('dept')->fixDeptPath();

            $this->syncUsers($provider, $settings, $treeResult['data'], $deptMap, $userMap, $stats);

            $model->saveMap('dept', $deptMap);
            $model->saveMap('user', $userMap);
            $model->saveLastSync($stats);

            $message = sprintf($this->lang->orgsync->syncResult, $stats['deptCreated'], $stats['deptUpdated'], $stats['userCreated'], $stats['userUpdated'], $stats['userSkipped']);
            return array('result' => 'success', 'message' => $message, 'data' => $stats);
        }
        catch(Throwable $e)
        {
            return array('result' => 'fail', 'message' => $e->getMessage());
        }
    }

    /**
     * 同步部门。
     * Sync depts.
     *
     * @param  object $settings
     * @param  array  $tree
     * @param  array  $deptMap
     * @param  array  $stats
     * @access protected
     * @return void
     */
    protected function syncDepts(object $settings, array $tree, array &$deptMap, array &$stats): void
    {
        $model      = $this->loadModel('orgsync');
        $localDepts = $model->getLocalDepts();

        $nodes    = array();
        $children = array();
        foreach($tree as $node)
        {
            $id   = (int)$node['id'];
            $pid  = (int)$node['pId'];
            $nodes[$id] = array('id' => $id, 'parent' => $pid, 'name' => $this->cleanName((string)$node['name'], 60));

            if(!isset($children[$pid])) $children[$pid] = array();
            $children[$pid][] = $id;
        }

        $ordered = array();
        $queue   = array();
        foreach($nodes as $id => $node) if($node['parent'] === 0) $queue[] = $id;
        while($queue)
        {
            $id = array_shift($queue);
            $ordered[$id] = true;
            if(isset($children[$id]))
            {
                foreach($children[$id] as $childID) if(!isset($ordered[$childID])) $queue[] = $childID;
            }
        }
        foreach($nodes as $id => $node) if(!isset($ordered[$id])) $ordered[$id] = true;

        $claimed = array();
        foreach($deptMap as $externalID => $localID)
        {
            if(isset($localDepts[(int)$localID])) $claimed[(int)$localID] = true;
        }

        foreach(array_keys($ordered) as $id)
        {
            $node   = $nodes[$id];
            $isRoot = $node['parent'] === 0;
            if($isRoot)
            {
                $deptMap[$id] = (int)$settings->parentDept;
                continue;
            }

            $parentLocalID = isset($deptMap[$node['parent']]) ? (int)$deptMap[$node['parent']] : (int)$settings->parentDept;
            if($parentLocalID > 0 && !isset($localDepts[$parentLocalID])) $parentLocalID = (int)$settings->parentDept;

            $localID = 0;
            if(isset($deptMap[$id]) && isset($localDepts[(int)$deptMap[$id]])) $localID = (int)$deptMap[$id];

            if(!$localID)
            {
                foreach($localDepts as $dept)
                {
                    if((int)$dept->parent !== $parentLocalID) continue;
                    if((string)$dept->name !== $node['name']) continue;
                    if(isset($claimed[(int)$dept->id])) continue;
                    $localID = (int)$dept->id;
                    break;
                }
            }

            if(!$localID)
            {
                $localID = $model->createDept($node['name'], $parentLocalID);
                if(!$localID)
                {
                    $this->addError($stats, sprintf($this->lang->orgsync->error->deptFailed, $node['name']));
                    continue;
                }

                $localDepts[$localID] = (object)array('id' => $localID, 'parent' => $parentLocalID, 'name' => $node['name'], 'order' => 0);
                $stats['deptCreated']++;
            }
            elseif((string)$localDepts[$localID]->name !== $node['name'] || (int)$localDepts[$localID]->parent !== $parentLocalID)
            {
                if($model->updateDept($localID, $node['name'], $parentLocalID))
                {
                    $localDepts[$localID]->name   = $node['name'];
                    $localDepts[$localID]->parent = $parentLocalID;
                    $stats['deptUpdated']++;
                }
                else
                {
                    $this->addError($stats, sprintf($this->lang->orgsync->error->deptFailed, $node['name']));
                }
            }
            else
            {
                $stats['deptSkipped']++;
            }

            $deptMap[$id]     = $localID;
            $claimed[$localID] = true;
        }
    }

    /**
     * 同步人员。
     * Sync users.
     *
     * @param  object $provider
     * @param  object $settings
     * @param  array  $tree
     * @param  array  $deptMap
     * @param  array  $userMap
     * @param  array  $stats
     * @access protected
     * @return void
     */
    protected function syncUsers(object $provider, object $settings, array $tree, array $deptMap, array &$userMap, array &$stats): void
    {
        $model       = $this->loadModel('orgsync');
        $localDepts  = $model->getLocalDepts();
        $defaultDept = (int)$settings->parentDept;
        if($defaultDept > 0 && !isset($localDepts[$defaultDept])) $defaultDept = 0;

        $rootIDs = array();
        foreach($tree as $node) if((int)$node['pId'] === 0) $rootIDs[(int)$node['id']] = true;

        $details = $this->fetchDeptUsers($provider, $tree, $stats);
        if(empty($details)) return;

        $localUsers = $model->getLocalUsers();
        $taken      = $model->getTakenAccounts();

        foreach($details as $userID => $remote)
        {
            if(isset($remote->active) && $remote->active === false)
            {
                $stats['userSkipped']++;
                continue;
            }

            $account   = $this->resolveAccount((string)$userID, $remote, $userMap, $localUsers);
            $realname  = $this->cleanName(!empty($remote->name) ? (string)$remote->name : (string)$userID, 100);
            $mobile    = substr($this->cleanMobile(isset($remote->mobile) ? (string)$remote->mobile : ''), 0, 20);
            $email     = substr($this->cleanEmail(isset($remote->email) ? (string)$remote->email : ''), 0, 90);
            $localDept = $this->resolveDept($remote, $deptMap, $localDepts, $rootIDs);

            if(!isset($localUsers[strtolower($account)]))
            {
                $account = $this->uniqueAccount($account, $localUsers, $taken);
                $deptID  = $localDept === null ? $defaultDept : $localDept;

                $userIDCreated = $model->createUser(array
                (
                    'account'  => $account,
                    'realname' => $realname,
                    'password' => $settings->initPassword,
                    'dept'     => $deptID,
                    'mobile'   => $mobile,
                    'email'    => $email
                ));
                if(!$userIDCreated)
                {
                    $this->addError($stats, sprintf($this->lang->orgsync->error->userFailed, $realname));
                    continue;
                }

                $localUsers[strtolower($account)] = (object)array('id' => $userIDCreated, 'account' => $account, 'realname' => $realname, 'mobile' => $mobile, 'email' => $email, 'dept' => $deptID);
                $userMap[(string)$userID] = $account;
                $stats['userCreated']++;
                continue;
            }

            $local  = $localUsers[strtolower($account)];
            $fields = array();
            if($realname !== (string)$local->realname) $fields['realname'] = $realname;
            if($mobile !== '' && $mobile !== (string)$local->mobile) $fields['mobile'] = $mobile;
            if($email !== '' && $email !== (string)$local->email) $fields['email'] = $email;
            if($localDept !== null && (int)$local->dept !== $localDept) $fields['dept'] = $localDept;

            if($fields)
            {
                if($model->updateUser((int)$local->id, $fields))
                {
                    foreach($fields as $field => $value) $local->$field = $value;
                    $stats['userUpdated']++;
                }
                else
                {
                    $this->addError($stats, sprintf($this->lang->orgsync->error->userFailed, $realname));
                }
            }
            else
            {
                $stats['userSkipped']++;
            }

            $userMap[(string)$userID] = $account;
        }
    }

    /**
     * 获取所有部门的用户。
     * Fetch users of all departments.
     *
     * @param  object $provider
     * @param  array  $tree
     * @param  array  $stats
     * @access protected
     * @return array
     */
    protected function fetchDeptUsers(object $provider, array $tree, array &$stats): array
    {
        $token = $provider->getToken();
        $users = array();
        $useV2 = method_exists($provider, 'getDeptUsers');

        foreach($tree as $node)
        {
            $deptID = (int)$node['id'];

            if($useV2)
            {
                $deptUsers = $this->fetchDeptUsersByV2($provider, $deptID);
                if($deptUsers !== false)
                {
                    foreach($deptUsers as $userID => $user) $users[$userID] = $user;
                    continue;
                }

                $useV2 = false;
                $this->addError($stats, $this->lang->orgsync->error->v2Fallback);
            }

            $response = $provider->queryAPI($provider->apiUrl . 'user/simplelist?access_token=' . rawurlencode((string)$token) . '&department_id=' . $deptID);
            if(empty($response) || empty($response->userlist)) continue;

            $userIDs = array();
            foreach($response->userlist as $user) $userIDs[] = (string)$user->userid;
            foreach($this->fetchUserDetails($provider, (string)$token, $userIDs, $stats) as $userID => $user) $users[$userID] = $user;
        }

        return $users;
    }

    /**
     * 使用 v2 接口分页获取部门用户。
     * Fetch dept users by v2 API.
     *
     * @param  object $provider
     * @param  int    $deptID
     * @access protected
     * @return array|false
     */
    protected function fetchDeptUsersByV2(object $provider, int $deptID): array|false
    {
        $users  = array();
        $cursor = 0;

        while(true)
        {
            $result = $provider->getDeptUsers($deptID, $cursor);
            if(!is_array($result) || !isset($result['result']) || $result['result'] != 'success') return false;

            foreach($result['data'] as $user)
            {
                if(empty($user->userid)) continue;
                $users[(string)$user->userid] = $this->normalizeV2User($user);
            }

            $cursor = isset($result['nextCursor']) ? (int)$result['nextCursor'] : 0;
            if(empty($result['hasMore']) || $cursor <= 0) break;
        }

        return $users;
    }

    /**
     * 统一 v2 与 v1 用户字段。
     * Normalize a v2 user object.
     *
     * @param  object $user
     * @access protected
     * @return object
     */
    protected function normalizeV2User(object $user): object
    {
        $normalized = new stdclass();
        $normalized->userid     = (string)$user->userid;
        $normalized->name       = isset($user->name) ? (string)$user->name : '';
        $normalized->mobile     = isset($user->mobile) ? (string)$user->mobile : '';
        $normalized->email      = isset($user->email) ? (string)$user->email : '';
        $normalized->department = isset($user->dept_id_list) && is_array($user->dept_id_list) ? array_map('intval', $user->dept_id_list) : array();
        $normalized->active     = !isset($user->active) || $user->active !== false;

        return $normalized;
    }

    /**
     * 批量获取钉钉用户详情（v1 回退）。
     * Fetch DingTalk user details by v1 API.
     *
     * @param  object $provider
     * @param  string $token
     * @param  array  $userIDs
     * @param  array  $stats
     * @access protected
     * @return array
     */
    protected function fetchUserDetails(object $provider, string $token, array $userIDs, array &$stats): array
    {
        $details = array();

        foreach(array_chunk($userIDs, 5) as $chunk)
        {
            $chunk = array_values($chunk);
            $urls  = array();
            foreach($chunk as $userID) $urls[] = $provider->apiUrl . 'user/get?access_token=' . rawurlencode($token) . '&userid=' . rawurlencode((string)$userID);

            foreach($provider->multiRequest($urls) as $index => $raw)
            {
                $response = json_decode((string)$raw);
                $errcode  = is_object($response) && isset($response->errcode) ? (int)$response->errcode : -1;

                if($errcode == 0 && !empty($response->userid))
                {
                    $details[(string)$response->userid] = $response;
                    continue;
                }

                if(in_array($errcode, array(88, 90018, -1), true))
                {
                    $retried = $this->fetchSingleUser($provider, $token, (string)$chunk[$index]);
                    if($retried)
                    {
                        $details[(string)$retried->userid] = $retried;
                        continue;
                    }
                }

                $message = is_object($response) && isset($response->errmsg) ? (string)$response->errmsg : '';
                $this->addError($stats, sprintf($this->lang->orgsync->error->userFetchFailedOne, $chunk[$index], trim($errcode . ' ' . $message)));
            }
        }

        return $details;
    }

    /**
     * 重试获取单个用户。
     * Retry fetching one user.
     *
     * @param  object $provider
     * @param  string $token
     * @param  string $userID
     * @access protected
     * @return object|false
     */
    protected function fetchSingleUser(object $provider, string $token, string $userID): object|false
    {
        for($attempt = 1; $attempt <= 3; $attempt++)
        {
            sleep($attempt);
            $response = $provider->queryAPI($provider->apiUrl . 'user/get?access_token=' . rawurlencode($token) . '&userid=' . rawurlencode($userID));
            if(is_object($response) && !empty($response->userid)) return $response;
        }

        return false;
    }

    /**
     * 根据钉钉用户确定禅道账号。
     * Resolve local account for a DingTalk user.
     *
     * @param  string $userID
     * @param  object $remote
     * @param  array  $userMap
     * @param  array  $localUsers
     * @access protected
     * @return string
     */
    protected function resolveAccount(string $userID, object $remote, array $userMap, array $localUsers): string
    {
        if(isset($userMap[$userID]) && isset($localUsers[strtolower($userMap[$userID])])) return (string)$localUsers[strtolower($userMap[$userID])]->account;
        if(isset($localUsers[strtolower($userID)])) return (string)$localUsers[strtolower($userID)]->account;

        if(!empty($remote->email))
        {
            $account = $this->loadModel('orgsync')->getAccountByEmail((string)$remote->email);
            if($account && isset($localUsers[strtolower($account)])) return (string)$localUsers[strtolower($account)]->account;
        }

        return $this->makeAccount($userID, $remote);
    }

    /**
     * 生成符合规则的账号。
     * Make an account by DingTalk user info.
     *
     * @param  string $userID
     * @param  object $remote
     * @access protected
     * @return string
     */
    protected function makeAccount(string $userID, object $remote): string
    {
        $candidates   = array();
        $candidates[] = $userID;

        if(!empty($remote->email))
        {
            $prefix       = strstr((string)$remote->email, '@', true);
            $candidates[] = preg_replace('/[^a-zA-Z0-9_.]/', '', (string)$prefix);
        }

        if(!empty($remote->mobile)) $candidates[] = preg_replace('/\D/', '', (string)$remote->mobile);

        foreach($candidates as $candidate)
        {
            $candidate = strtolower(substr(trim((string)$candidate), 0, 30));
            $candidate = rtrim($candidate, '._');
            if(validater::checkAccount($candidate)) return $candidate;
        }

        return 'ding' . substr(md5($userID), 0, 8);
    }

    /**
     * 保证账号唯一。
     * Make the account unique.
     *
     * @param  string $account
     * @param  array  $localUsers
     * @access protected
     * @return string
     */
    protected function uniqueAccount(string $account, array $localUsers, array $taken): string
    {
        if(!isset($localUsers[strtolower($account)]) && !isset($taken[strtolower($account)])) return $account;

        for($index = 2; $index <= 999; $index++)
        {
            $suffix    = (string)$index;
            $candidate = substr($account, 0, 30 - strlen($suffix)) . $suffix;
            if(!isset($localUsers[strtolower($candidate)]) && !isset($taken[strtolower($candidate)])) return $candidate;
        }

        return substr($account, 0, 24) . substr(md5(uniqid('', true)), 0, 6);
    }

    /**
     * 清理名称，去掉标签并限制长度。
     * Clean a name.
     *
     * @param  string $name
     * @param  int    $maxLength
     * @access protected
     * @return string
     */
    protected function cleanName(string $name, int $maxLength): string
    {
        $name = trim(strip_tags($name));
        if($name === '') $name = $this->lang->orgsync->unnamed;

        return mb_substr($name, 0, $maxLength, 'UTF-8');
    }

    /**
     * 确定人员所属的本地部门。
     * Resolve local dept for a DingTalk user.
     *
     * @param  object $remote
     * @param  array  $deptMap
     * @param  array  $localDepts
     * @param  array  $rootIDs
     * @access protected
     * @return int|null null 表示钉钉未返回可用的部门信息
     */
    protected function resolveDept(object $remote, array $deptMap, array $localDepts, array $rootIDs): int|null
    {
        $departments = array();
        if(!empty($remote->department) && is_array($remote->department)) $departments = $remote->department;
        if(!empty($remote->dept_id_list) && is_array($remote->dept_id_list)) $departments = array_merge($departments, $remote->dept_id_list);
        if(empty($departments)) return null;

        $bestDept  = null;
        $bestGrade = -1;
        foreach($departments as $deptID)
        {
            $deptID = (int)$deptID;
            if(isset($rootIDs[$deptID])) continue;
            if(!isset($deptMap[$deptID])) continue;

            $localID = (int)$deptMap[$deptID];
            if(!isset($localDepts[$localID])) continue;

            $grade = isset($localDepts[$localID]->grade) ? (int)$localDepts[$localID]->grade : 0;
            if($grade > $bestGrade || ($grade === $bestGrade && ($bestDept === null || $localID < $bestDept)))
            {
                $bestDept  = $localID;
                $bestGrade = $grade;
            }
        }

        return $bestDept;
    }

    /**
     * 校验并清理邮箱。
     * Clean email.
     *
     * @param  string $email
     * @access protected
     * @return string
     */
    protected function cleanEmail(string $email): string
    {
        $email = trim($email);
        return $email !== '' && validater::checkEmail($email) ? $email : '';
    }

    /**
     * 校验并清理手机号。
     * Clean mobile.
     *
     * @param  string $mobile
     * @access protected
     * @return string
     */
    protected function cleanMobile(string $mobile): string
    {
        $mobile = preg_replace('/\s+/', '', trim($mobile));
        return $mobile !== '' && validater::checkMobile($mobile) ? $mobile : '';
    }

    /**
     * 记录同步错误。
     * Add a sync error.
     *
     * @param  array  $stats
     * @param  string $message
     * @access protected
     * @return void
     */
    protected function addError(array &$stats, string $message): void
    {
        if(count($stats['errors']) < $this->config->orgsync->maxErrors) $stats['errors'][] = $message;
    }
}
