<?php

namespace App\Http\Controllers\admin;

use App\Models\Admin\AdminAuthGroupAccess;
use App\Models\Admin\AdminUser;
use App\Models\Admin\AdminUserData;
use App\tools\ReturnCode;
use App\tools\Tools;
use Cache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Psr\SimpleCache\InvalidArgumentException;

class UserController extends BaseController
{

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->modelObj = new AdminUser();
    }

    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index()
    {
        $limit = $this->request->get('size', config('laravelApi.limit_default'));
        $start = $this->request->get('page', 1);
        $type = $this->request->get('type', '');
        $keywords = $this->request->get('keywords', '');
        $status = $this->request->get('status', '');

        $obj = $this->modelObj;
        if (strlen($status)) {
            $obj = $obj->where('status', $status);
        }
        if ($type) {
            switch ($type) {
                case 1:
                    $obj = $obj->where('username', "like", "%{$keywords}%");
                    break;
                case 2:
                    $obj = $obj->where('nickname', "like", "%{$keywords}%");
                    break;
            }
        }

        $listObj = $obj->with('userData')
            ->orderByDesc('create_time')
            ->paginate($limit, ['*'], "page", $start);

        $listInfo = $listObj->items();
        $idArr = array_column($listInfo, 'id');

        $userGroup = (new AdminAuthGroupAccess())->whereIn('uid', $idArr)->get()->toArray();
        $userGroup = Tools::buildArrByNewKey($userGroup, 'uid');

        foreach ($listInfo as &$value) {
            if ($value['userData']) {
                $value['userData']['last_login_ip'] = long2ip($value['userData']['last_login_ip']);
                $value['userData']['last_login_time'] = date('Y-m-d H:i:s', $value['userData']['last_login_time']);
                $value['create_ip'] = long2ip($value['create_ip']);
            }
            if (isset($userGroup[$value['id']])) {
                $value['group_id'] = explode(',', $userGroup[$value['id']]['group_id']);
            } else {
                $value['group_id'] = [];
            }
        }

        return $this->buildSuccess([
            'list' => $listInfo,
            'count' => $listObj->total(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return array
     */
    public function create(): array
    {
        $groups = '';
        $postData = $this->request->post();
        $postData['create_ip'] = sprintf("%u", ip2long($this->request->ip()));
        $postData['password'] = Tools::userMd5($postData['password']);
        if (isset($postData['group_id']) && $postData['group_id']) {
            $groups = trim(implode(',', $postData['group_id']), ',');
            unset($postData['group_id']);
        }
        foreach ($postData as $k => $v) {
            if ($v == null) {
                unset($postData[$k]);
            }
        }
        $res = AdminUser::create($postData);
        if (!$res) {
            return $this->buildFailed(ReturnCode::DB_SAVE_ERROR);
        }
        AdminAuthGroupAccess::create([
            'uid' => $res->id,
            'group_id' => $groups,
        ]);

        return $this->buildSuccess();
    }

    /**
     * 获取当前组的全部用户
     * @return array
     */
    public function getUsers(): array
    {
        $limit = $this->request->get('size', config('apiadmin.ADMIN_LIST_DEFAULT'));
        $page = $this->request->get('page', 1);
        $gid = $this->request->get('gid', 0);
        if (!$gid) {
            return $this->buildFailed(ReturnCode::PARAM_INVALID, '非法操作');
        }

        $totalNum = (new AdminAuthGroupAccess())->whereRaw('find_in_set(?, `group_id`)', [$gid])->count();
        $start = $limit * ($page - 1);
        $userInfo = DB::table('admin_user as au')
        ->leftJoin('admin_auth_group_access as aaga', 'aaga.uid', '=', 'au.id')
        ->whereRaw('find_in_set(?, aaga.group_id)', [$gid])
        ->orderBy('au.create_time', 'desc')
        ->offset($start)
        ->limit($limit)
        ->get()
        ->toArray();

        $uidArr = array_column($userInfo, 'id');
        $userData = (new AdminUserData())->whereIn('uid', $uidArr)->get()->toArray();
        $userData = Tools::buildArrByNewKey($userData, 'uid');

        foreach ($userInfo as $key => $value) {
            if (isset($userData[$value->id])) {
                $userInfo[$key]->last_login_ip = long2ip($userData[$value->id]['last_login_ip']);
                $userInfo[$key]->login_times = $userData[$value->id]['login_times'];
                $userInfo[$key]->last_login_time = date('Y-m-d H:i:s', $userData[$value->id]['last_login_time']);
            }
            $userInfo[$key]->create_ip = long2ip($userInfo[$key]->create_ip);
        }

        return $this->buildSuccess([
            'list' => $userInfo,
            'count' => $totalNum,
        ]);
    }

    public function changeStatus(): array
    {
        $id = $this->request->get('id');

        if (Tools::isAdministrator($id)) {
            return $this->buildFailed(ReturnCode::INVALID, "超级管理员不允许修改状态");
        }
        $res = $this->_changeStatus();
        if (!$res) {
            return $this->buildFailed(ReturnCode::DB_SAVE_ERROR);
        }
        if ($oldAdmin = cache('Login:' . $id)) {
            Cache::delete('Login:' . $oldAdmin);
        }

        return $this->buildSuccess();
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function edit()
    {
        $groups = '';
        $id = $this->request->post("id");
        $password = $this->request->post("password");
        $username = $this->request->post("username");
        $nickname = $this->request->post("nickname");
        $group_id = $this->request->post("group_id");
        $password = Tools::userMd5($password);


        if (isset($group_id) && $group_id) {
            $groups = trim(implode(',', $group_id), ',');
        }
        $res = $this->modelObj->where('id', $id)->update([
            "password" => $password,
            "username" => $username,
            "nickname" => $nickname,
        ]);
        if ($res === false) {
            return $this->buildFailed(ReturnCode::DB_SAVE_ERROR);
        }
        $has = (new AdminAuthGroupAccess())->where('uid', $id)->get()->toArray();
        if (count($has)) {
            (new AdminAuthGroupAccess())->where("uid", $id)->update([
                'group_id' => $groups,
            ]);
        } else {
            (new AdminAuthGroupAccess())->create([
                'uid' => $id,
                'group_id' => $groups,
            ]);
        }
        if ($oldAdmin = cache('Login:' . $id)) {
            Cache::delete('Login:' . $oldAdmin);
        }

        return $this->buildSuccess();
    }

    /**
     * 修改自己的信息
     * @return array
     */
    public function own()
    {
        $postData = $this->request->post();
        $headImg = $postData['head_img'];

        if ($postData['password'] && $postData['oldPassword']) {
            $oldPass = Tools::userMd5($postData['oldPassword']);
            unset($postData['oldPassword']);
            if ($oldPass === $this->userInfo['password']) {
                $postData['password'] = Tools::userMd5($postData['password']);
            } else {
                return $this->buildFailed(ReturnCode::INVALID, '原始密码不正确');
            }
        } else {
            unset($postData['password']);
            unset($postData['oldPassword']);
        }
        $postData['id'] = $this->userInfo['id'];
        unset($postData['head_img']);
        $res = $this->modelObj->update($postData);
        if ($res === false) {
            return $this->buildFailed(ReturnCode::DB_SAVE_ERROR);
        }
        $userData = (new AdminUserData())->where('uid', $postData['id'])->find();
        $userData->head_img = $headImg;
        $userData->save();
        if ($oldWiki = cache('WikiLogin:' . $postData['id'])) {
            cache('WikiLogin:' . $oldWiki, null);
        }

        return $this->buildSuccess();
    }


    /**
     * Remove the specified resource from storage.
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function destroy()
    {
        $id = $this->request->get('id');
        if (!$id) {
            return $this->buildFailed(ReturnCode::EMPTY_PARAMS, '缺少必要参数');
        }

        $isAdmin = Tools::isAdministrator($id);
        if ($isAdmin) {
            return $this->buildFailed(ReturnCode::INVALID, '超级管理员不能被删除');
        }
        AdminUser::destroy($id);
        AdminAuthGroupAccess::where(['uid' => $id])->delete();
        if ($oldAdmin = cache('Login:' . $id)) {
            Cache::delete('Login:' . $oldAdmin);
        }

        return $this->buildSuccess();
    }
}
