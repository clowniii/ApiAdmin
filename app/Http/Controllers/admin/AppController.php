<?php

namespace App\Http\Controllers\admin;

use App\Models\Admin\ApiGroup;
use App\Models\Admin\ApiList;
use App\Models\Admin\ApiApp;
use App\tools\ReturnCode;
use App\tools\Strs;
use App\tools\Tools;
use Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Psr\SimpleCache\InvalidArgumentException;

class AppController extends BaseController
{

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->modelObj = new ApiApp();
    }

    /**
     * Display a listing of the resource.
     *
     * @return array
     */
    public function index(Request $request)
    {
        //
        $limit = $request->get("size", config("laravelApi.limit_default"));
        $start = $request->get('page', 1);
        $keywords = $request->get('keywords', '');
        $type = $request->get('type', '');
        $status = $request->get('status', '');

        $obj = $this->modelObj;
        if (strlen($status)) {
            $obj = $obj->where('app_status', $status);
        }
        if ($type) {
            switch ($type) {
                case 1:
                    $obj = $obj->where('app_id', $keywords);
                    break;
                case 2:
                    $obj = $obj->where('app_name', 'like', "%{$keywords}%");
                    break;
            }
        }
        $listObj = $obj->orderByDesc('app_add_time')->paginate($limit, ['*'], 'page', $start);

        return $this->buildSuccess([
            'list' => $listObj->items(),
            'count' => $listObj->total()
        ]);
    }

    public function getAppInfo(): array
    {
        $apiArr = (new ApiList())->get();
        foreach ($apiArr as $api) {
            $res['apiList'][$api['group_hash']][] = $api;
        }
        $groupArr = (new ApiGroup())->get()->toArray();
        $res['groupInfo'] = array_column($groupArr, 'name', 'hash');
        $id = $this->request->get('id', 0);
        if ($id) {
            $appInfo = (new ApiApp())->where('id', $id)->first()->toArray();
            $res['app_detail'] = json_decode($appInfo['app_api_show'], true);
        } else {
            $res['app_id'] = mt_rand(1, 9) . Strs::randString(7, 1);
            $res['app_secret'] = Strs::randString(32);
        }

        return $this->buildSuccess($res);
    }

    public function refreshAppSecret($data): array
    {
        $data['app_secret'] = Strs::randString(32);

        return $this->buildSuccess($data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return array
     */
    public function create()
    {
        $postData = $this->request->post();
        $data = [
            'app_id' => $postData['app_id'],
            'app_secret' => $postData['app_secret'],
            'app_name' => $postData['app_name'],
            'app_info' => $postData['app_info'],
            'app_group' => $postData['app_group'],
            'app_add_time' => time(),
            'app_api' => '',
            'app_api_show' => ''
        ];
        if (isset($postData['app_api']) && $postData['app_api']) {
            $appApi = [];
            $data['app_api_show'] = json_encode($postData['app_api']);
            foreach ($postData['app_api'] as $value) {
                $appApi = array_merge($appApi, $value);
            }
            $data['app_api'] = implode(',', $appApi);
        }
        $res = ApiApp::create($data);
        if (!$res) {
            return $this->buildFailed(ReturnCode::DB_SAVE_ERROR);
        }

        return $this->buildSuccess();
    }

    /**
     * @throws InvalidArgumentException
     */
    public function changeStatus(): array
    {
        $id = $this->request->get('id');

        $res = $this->_changeStatus($id);

        if ($res === false) {
            return $this->buildFailed(ReturnCode::DB_SAVE_ERROR);
        }
        $appInfo = (new ApiApp())->find($id);
        Cache::delete('AccessToken:Easy:' . $appInfo['app_secret']);
        if ($oldWiki = cache('WikiLogin:' . $id)) {
            Cache::delete('WikiLogin:' . $oldWiki);
        }

        return $this->buildSuccess();
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit()
    {
        $postData = $this->request->post();
        $data = [
            'app_secret' => $postData['app_secret'],
            'app_name' => $postData['app_name'],
            'app_info' => $postData['app_info'],
            'app_group' => $postData['app_group'],
            'app_api' => '',
            'app_api_show' => ''
        ];
        if (isset($postData['app_api']) && $postData['app_api']) {
            $appApi = [];
            $data['app_api_show'] = json_encode($postData['app_api']);
            foreach ($postData['app_api'] as $value) {
                $appApi = array_merge($appApi, $value);
            }
            $data['app_api'] = implode(',', $appApi);
        }

        $res = (new ApiApp())->where('id', $postData['id'])->update($data);
        if (!$res) {
            return $this->buildFailed(ReturnCode::DB_SAVE_ERROR, "编辑失败");
        }

        $appInfo = (new ApiApp())->find($postData['id']);

        Cache::delete('AccessToken:Easy:' . $appInfo['app_secret']);
        if ($oldWiki = cache('WikiLogin:' . $postData['id'])) {
            Cache::delete('WikiLogin:' . $oldWiki);
        }

        return $this->buildSuccess();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy()
    {
        $id = $this->request->get('id');
        if (!$id) {
            return $this->buildFailed(ReturnCode::EMPTY_PARAMS, '缺少必要参数');
        }
        $appInfo = (new ApiApp())->find($id);
        Cache::delete('AccessToken:Easy:' . $appInfo['app_secret']);
        ApiApp::destroy($id);
        if ($oldWiki = cache('WikiLogin:' . $id)) {
            Cache::delete('WikiLogin:' . $oldWiki);
        }

        return $this->buildSuccess();
    }
}
