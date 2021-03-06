<?php
if (!defined('IN_DZZ')) {
    exit('Access Denied');
}

class table_resources extends dzz_table
{
    public $noperm = false;

    public function __construct()
    {

        $this->_table = 'resources';
        $this->_pk = 'rid';

        parent::__construct();
    }

    public function insert_data($data)
    {
        $rid = self::create_id();
        $data['rid'] = $rid;
        if (parent::insert($data)) {
            return $rid;
        }
        return false;

    }

    //生成主键rid
    public function create_id()
    {
        $microtime = microtime();
        list($msec, $sec) = explode(' ', $microtime);
        $msec = $msec * 1000000;
        $idstr = md5($sec . $msec . random(6));
        return $idstr;
    }

    public function getparentsuri($pfid)
    {
        $path = array();
        if ($parent = DB::result_first('select pfid,fname from %t where fid = %d', array('folder', $pfid))) {
            if ($parent['pfid'] > 0 && $parent['pfid'] != $pfid) {
                $path[] = $parent['fname'];
                self::getparenturi($parent['pfid']);
            }
        }
        $path = array_reverse($path);
        $path = implode('/', $path);
        return $path;
    }

    public function rename_by_rid($rid, $newname)
    {
        global $_G;
        $uid = $_G['uid'];
        if (!$infoarr = $this->fetch_info_by_rid($rid)) {
            return array('error' => lang('file_not_exist'));
        }
        $updatepath = false;
        if ($infoarr['type'] == 'folder') {
            $updatepath = true;
        }
        $fid = $infoarr['pfid'];
        if ($infoarr['gid'] > 0) {
            $pfid = $infoarr['pfid'];
            $perm = perm_check::getPerm($pfid);
            if ($perm > 0) {
                if (!perm_binPerm::havePower('edit2', $perm) && !(perm_binPerm::havePower('edit1', $perm) && $uid == $infoarr['uid'])) {
                    return array('error' => true);
                }
            }
        }
        $setarr = array(
            'isdelete' => 1,
            'deldateline' => time()
        );
        $position = C::t('resources_path')->fetch_pathby_pfid($fid);
        $position = preg_replace('/dzz:(.+?):/', '', $position);
        if (DB::update($this->_table, array('name' => $newname, 'dateline' => TIMESTAMP), array('rid' => $rid))) {
            if ($updatepath) {
                C::t('folder')->update($infoarr['oid'], array('fname' => $newname));
                C::t('resources_path')->update_path_by_fid($infoarr['oid'], $newname);
            }
            //更新属性表数据
            C::t('resources_attr')->update_by_skey($rid, $infoarr['vid'], array('title' => $newname));
            $statisdata = array(
                'uid' => getglobal('uid'),
                'edits' => 1,
                'editdateline' => TIMESTAMP
            );
            C::t('resources_statis')->add_statis_by_rid($rid, $statisdata);
            $hash = C::t('resources_event')->get_showtpl_hash_by_gpfid($infoarr['pfid'], $infoarr['gid']);
            $eventdata = array('username' => $_G['username'], 'position' => $position, 'filename' => $infoarr['name'], 'newfilename' => $newname, 'hash' => $hash);
            if (C::t('resources_event')->addevent_by_pfid($infoarr['pfid'], 'rename_file', 'rename', $eventdata, $infoarr['gid'], $rid, $infoarr['name'])) {
                return array('newname' => $newname);
            } else {
                DB::update('resources_attr', array('title' => $infoarr['name']), array('rid' => $rid, 'vid' => $infoarr['vid']));
                DB::update($this->_table, array('name' => $infoarr['name'], 'dateline' => $infoarr['dateline']), array('rid' => $rid));
                return array('error' => true);
            }
        }
    }

    //查询文件表基础信息 $notisdelete是否是已删除
    public function fetch_info_by_rid($rid, $notisdelete = false)
    {
        $rid = trim($rid);
        $where = ' and 1 ';
        if ($notisdelete) {
            $where .= ' and isdelete < 1 ';
        }
        return DB::fetch_first("select * from %t where rid = %s $where", array($this->_table, $rid));
    }

    //检查权限
    public function check_groupperm_by_pfids($pfids, $action, $gid = 0, $bz = '')
    {
        global $_G;
        if (!is_array($pfids)) $pfids = array($pfids);
        //bz判断暂时注释
        /* if($bz){
            foreach($pfids as $v){
             if(!self::checkperm_Container($v,$action,$bz)){
                 return false;
              }
            }
         }*/
        //获取目录权限和超级权限
        $folderpermarr = DB::fetch_all("select perm_inherit,fsperm,uid from %t where fid in(%n)", array('folder', $pfids));
        //判断目录超级权限
        foreach ($folderpermarr as $v) {
            if (!perm_FolderSPerm::isPower($v['fsperm'], $action)) return false;
        }
        $uid = $_G['uid'];
        if ($_G['adminid'] == 1) return true;
        $ismoderator = C::t('organization_admin')->chk_memberperm($gid, $_G['uid']);
        if ($ismoderator) return true;
        //判断目录权限
        if (DB::result_first("select count(*) from %t where uid=%d and orgid=%d", array('organization_user', $uid, $gid)) < 1) {
            return false;
        }
        $permarr = perm_binPerm::getPowerArr();
        foreach ($folderpermarr as $v) {
            if (!($v['perm_inherit'] & $permarr[$action . '2']) && !($v['perm_inherit'] & $permarr[$action . '1'])) {
                return false;
            }
        }
        return true;

    }

    //删除文件(移动文件到回收站)
    public function recyle_by_rid($rid)
    {
        global $_G;
        $uid = $_G['uid'];
        $rid = trim($rid);
        $dels = array();
        //判断文件是否存在
        if (!$infoarr = DB::fetch_first("select * from %t where rid = %s", array($this->_table, $rid))) {
            return false;
        }
        $fid = $infoarr['pfid'];
        $setarr = array(
            'pfid' => -1,
            'isdelete' => 1,
            'deldateline' => TIMESTAMP
        );
        $setarr1 = array(
            'isdelete' => 1,
            'deldateline' => TIMESTAMP
        );
        //如果删除的是文件夹
        if ($infoarr['type'] == 'folder') {
            $currentfid = $infoarr['oid'];
            //获取文件夹fid集合
            $fids = C::t('resources_path')->fetch_folder_containfid_by_pfid($currentfid);
            if ($infoarr['gid'] > 0) {
                $pfids = $fids;
                $pfids[] = $fid;
                //权限判断
                if (!self::check_groupperm_by_pfids($pfids, 'delete', $infoarr['gid'])) {
                    return array('error' => lang('has_no_privilege_file'));
                }
            }
            $rids = array();
            //获取当前文件下所有下级rid
            foreach (DB::fetch_all("select rid from %t where oid in(%n) or pfid in(%n) and rid != %s", array($this->_table, $fids, $fids, $rid)) as $v) {
                $rids[] = $v['rid'];
            }
            $rfids = $fids;
            $index = array_search($currentfid, $rfids);
            unset($rfids[$index]);
            $ridsstatus = (count($rids) > 0) ? true : false;
            $fidssstatus = (count($rfids) > 0) ? true : false;
            if (DB::update($this->table, $setarr, array('rid' => $rid))) {
                $infoarr['deldateline'] = $setarr['deldateline'];
                $infoarr['isdelete'] = $setarr['isdelete'];
                //更改下级目录删除状态
                if ($ridsstatus) DB::update($this->table, $setarr1, 'rid in(' . dimplode($rids) . ')');
                //查询回收站是否有相同数据，如果有合并回收站数据
                if ($recyledata = C::t('resources_recyle')->fetch_by_rid($infoarr['rid'])) {
                    if (DB::update('folder', $setarr, 'fid =' . $currentfid)) {
                        if ($fidssstatus) DB::update('folder', $setarr1, 'fid in(' . dimplode($rfids) . ')');
                        DB::update('resources_recyle', array('deldateline' => $infoarr['deldateline'], 'uid' => $uid), array('id' => $recyledata['id']));
                    } else {
                        if ($ridsstatus) DB::update($this->table, array('isdelete' => 0, 'deldateline' => 0, 'pfid' => $infoarr['pfid']), 'rid in(' . dimplode($rids) . ')');
                        DB::update($this->table, array('isdelete' => 0, 'deldateline' => 0, 'pfid' => $infoarr['pfid']), 'rid = ' . $rid);
                        return array('error' => lang('do_failed'));
                    }
                } else {
                    if (DB::update('folder', $setarr, 'fid =' . $currentfid)) {
                        if ($fidssstatus) DB::update('folder', $setarr1, 'fid  in(' . dimplode($rfids) . ')');
                        C::t('resources_recyle')->insert_data($infoarr);
                    } else {
                        if ($ridsstatus) DB::update($this->table, array('isdelete' => 0, 'deldateline' => 0, 'pfid' => $infoarr['pfid']), 'rid in(' . dimplode($rids) . ')');
                        DB::update($this->table, array('isdelete' => 0, 'deldateline' => 0, 'pfid' => $infoarr['pfid']), 'rid = ' . $rid);
                        return array('error' => lang('do_failed'));
                    }
                }

            }
            $dels[] = $rid;
        } else {
            //文件权限判断
            if ($infoarr['gid'] > 0) {
                if (!perm_check::checkperm_Container($fid, 'delete2') && !(perm_check::checkperm_Container($fid, 'delete1') && $uid == $infoarr['uid'])) {
                    return array('error' => lang('no_privilege'));
                }
            }
            if (DB::result_first("select count(*) from %t where rid = %s", array('resources_recyle', $rid))) {
                return array('error' => lang('file_isdelete_in_recycle'));
            }
            //执行删除
            if (DB::update($this->table, $setarr, array('rid' => $rid))) {
                //修改分享表状态
                C::t('shares')->change_by_rid($rid);
                $infoarr['deldateline'] = $setarr['deldateline'];
                $infoarr['isdelete'] = $setarr['isdelete'];
                //数据放入回收站表
                if (C::t('resources_recyle')->insert_data($infoarr)) {
                    $dels[] = $rid;
                } else {//放入回收站失败处理
                    DB::update($this->table, array('isdelete' => 0, 'deldateline' => 0, 'pfid' => $infoarr['pfid']), array('rid' => $rid));
                }
            }
        }

        return $dels;
    }

    /*返回1，正常删除，需删除附属表数据，返回2，非删除状态内容，只删除回收站表数据，返回3删除失败
     *$force=>是否彻底删除(即是否强制删除非删除状态文件)
     *  */
    public function deletesourcedata($resource, $force = false)
    {
        $type = $resource['type'];
        $oid = $resource['oid'];
        switch ($type) {
            case 'folder':
                return C::t('folder')->delete_by_fid($oid, $force);
            case 'link':
                C::t('collect')->delete_by_cid($oid);
                return 1;
            case 'app':
                return 1;
            case 'user':
                return 1;
            case 'pan':
                return 1;
            case 'storage':
                return 1;
            default :
                if(!$resource['vid']){
                    C::t('attachment')->addcopy_by_aid($resource['aid'], -1);
                }
                return 1;
        }
    }

    public function delete_by_rid($rid, $force = false)
    { //删除图标
        global $_G;
        $data = self::fetch_by_rid($rid);
        if (!perm_check::checkperm('delete', $data)) {
            return array('error' => lang('no_privilege'));
        }
        $status = self::deletesourcedata($data, $force);
        //删除相关数据
        if ($status == 1) {
            //刪除属性表数据
            C::t('resources_attr')->delete_by_rid($rid);
            //删除事件表数据
            C::t('resources_event')->delete_by_rid($rid);
            //删除标签表数据
            C::t('resources_tag')->delete_by_rid($rid);
            //删除回收站数据
            C::t('resources_recyle')->delete_by_rid($rid);
            //删除版本表数据
            if ($data['vid']) {
                C::t('resources_version')->delete_by_rid($rid);
            }/* else {
                //空间计算移动至版本表
                if ($data['size']) SpaceSize(-$data['size'], $data['gid'], 1, $data['uid']);
            }*/
            //删除收藏表数据
            C::t('resources_collect')->delete_by_rid($rid);
            //删除统计表数据
            C::t('resources_statis')->delete_by_rid($rid);
            //删除resources表数据
            if (self::delete($rid)) {
                //记录删除事件
                $hash = C::t('resources_event')->get_showtpl_hash_by_gpfid($data['pfid'], $data['gid']);
                $eventdata = array('username' => $_G['username'], 'position' => $data['relpath'], 'filename' => $data['name'], 'hash' => $hash);
                C::t('resources_event')->addevent_by_pfid($data['pfid'], 'finallydel_file', 'finallydelete', $eventdata, $data['gid'], $rid, $data['name']);
                return true;
            } else {
                return false;
            }
        } elseif ($status == 2) {
            //删除回收站数据
            C::t('resources_recyle')->delete_by_rid($rid);
        } else {
            return false;
        }

    }

    public function getsourcedata($rid)
    {
        //查询索引表数据
        $resourcedata = array();
        if (!$resourcedata = self::fetch($rid)) {
            return array();
        }
        //查询文件夹信息
        if ($resourcedata['type'] == 'folder') {
            $folder = C::t('folder')->fetch_by_fid($resourcedata['oid']);
            $resourcedata = array_merge($resourcedata, $folder);
            $folderattr = C::t('folder_attr')->fetch_all_folder_setting_by_fid($resourcedata['oid']);
            $resourcedata = array_merge($resourcedata, $folderattr);
        }
        //查询文件路径
        if ($path = C::t('resources_path')->fetch_pathby_pfid($resourcedata['pfid'])) {
            $resourcedata['path'] = ($resourcedata['type'] == 'folder') ? $path . $resourcedata['name'] . '/' : $path . $resourcedata['name'];
        }
        $vdata = array();
        if ($resourcedata['vid'] > 0) {
            $vdata = C::t('resources_version')->fetch($resourcedata['vid']);
        }
        $versiondata = array();
        if ($vdata) {
            $versiondata = array(
                /*'aid'=>$vdata['aid'],
                'name'=>$vdata['vname'],
                'size'=>$vdata['size'],*/
                'dateline' => $vdata['dateline'],//文件修改时间
                /*'ext'=>$vdata['ext'],
                'type'=>$vdata['type']*/
            );
        }
        //查询文件属性信息
        $attrdata = C::t('resources_attr')->fetch_by_rid($rid, $resourcedata['vid']);
        $resourcedata = array_merge($resourcedata, $attrdata);

        $resourcedata = array_merge($resourcedata, $versiondata);

        if ($resourcedata['aid']) {//查询附件数据
            $attachment = C::t('attachment')->fetch($resourcedata['aid']);
            //附件表上传时间和文件创建时间字段名称冲突
            $attachment['olddateline'] = $attachment['dateline'];
            unset($attachment['dateline']);
            $resourcedata = array_merge($resourcedata, $attachment);
        }
        return $resourcedata;
    }

    public function fetch_by_rid($rid, $force_from_db = false)
    { //返回一条数据同时加载资源表数据
        global $_G;
        $data = array();
        if (!$data = self::getsourcedata($rid)) {
            return array();
        }
        $data['size'] = isset($data['size']) ? $data['size'] : 0;
        if ($data['type'] == 'image') {
            $data['img'] = DZZSCRIPT . '?mod=io&op=thumbnail&size=small&path=' . dzzencode('attach::' . $data['aid']);
            $data['url'] = DZZSCRIPT . '?mod=io&op=thumbnail&size=large&path=' . dzzencode('attach::' . $data['aid']);
        } elseif ($data['type'] == 'attach' || $data['type'] == 'document') {
            $data['img'] = geticonfromext($data['ext'], $data['type']);
            //$data['img']=DZZSCRIPT.'?mod=io&op=thumbnail&size=small&path='.dzzencode('attach::'.$data['aid']);
            $data['url'] = DZZSCRIPT . '?mod=io&op=getStream&path=' . dzzencode('attach::' . $data['aid']);
        } elseif ($data['type'] == 'shortcut') {
            //$data['img']=isset($data['tdata']['img'])?$data['tdata']['img']:geticonfromext($data['tdata']['ext'],$data['tdata']['type']);
            $data['ttype'] = $data['tdata']['type'];
            $data['ext'] = $data['tdata']['ext'];
        } elseif ($data['type'] == 'dzzdoc') {
            $data['url'] = DZZSCRIPT . '?mod=document&icoid=' . dzzencode('attach::' . $data['aid']);
            $data['img'] = isset($data['img']) ? $data['img'] : geticonfromext($data['ext'], $data['type']);
        } elseif ($data['type'] == 'folder') {
            $contaions = self::get_contains_by_fid($data['oid'], true);
            $data['contaions'] = $contaions;
            $relativepath = str_replace(':', '', strrchr($data['path'], ':'));
            $data['position'] = substr($relativepath, 0, strlen($relativepath) - 1);
            $data['fsize'] = formatsize($contaions['size']);
            $data['ffsize'] = lang('property_info_size', array('fsize' => formatsize($contaions['size']), 'size' => $contaions['size']));
            $data['contain'] = lang('property_info_contain', array('filenum' => $contaions['contain'][0], 'foldernum' => $contaions['contain'][1]));
            $data['img'] = '/dzz/images/extimg/folder.png';
        } else {
            $data['img'] = isset($data['img']) ? $data['img'] : geticonfromext($data['ext'], $data['type']);
        }
        if ($data['appid']) {
            $imgs = C::t('app_market')->fetch_appico_by_appid($data['appid']);
            $data['img'] = ($imgs) ? 'data/attachment/' . $imgs : geticonfromext($data['ext'], $data['type']);
        }
        if (empty($data['name'])) $data['name'] = $data['title'];
        $data['url'] = isset($data['url']) ? replace_canshu($data['url']) : '';
        $data['ftype'] = getFileTypeName($data['type'], $data['ext']);
        $data['fdateline'] = dgmdate($data['dateline'], 'Y-m-d H:i:s');
        $data['fsize'] = formatsize($data['size']);
        $data['ffsize'] = lang('property_info_size', array('fsize' => formatsize($data['size']), 'size' => $data['size']));
        $data['relativepath'] = $data['path'] ? $data['path'] : '';
        $data['relpath'] = dirname(preg_replace('/dzz:(.+?):/', '', $data['relativepath'])) . '/';
        $data['path'] = $data['rid'];
        $data['bz'] = '';
        $data['collect'] = C::t('resources_collect')->fetch_by_rid($rid);
        if ($data['remote'] > 1) $data['rbz'] = io_remote::getBzByRemoteid($data['remote']);

        //增加安全相关的路径
        $data['dpath'] = dzzencode($data['path']);
        $data['apath'] = $data['aid'] ? dzzencode('attach::' . $data['aid']) : $data['dpath'];
        if (!$data['sperm']) $data['sperm'] = perm_FileSPerm::typePower($data['type'], $data['ext']);
        Hook::listen('filter_resource_rid', $data);//数据过滤挂载点
        return $data;
    }

    //查询群组id
    public function fetch_gid_by_rid($rid)
    {
        return DB::result_first("select gid from %t where rid = %d", array($this->_table, $rid));
    }

    //查询多个文件右侧信息
    public function fetch_rightinfo_by_rid($rids)
    {
        if (!is_array($rids)) $rids = (array)$rids;
        $fileinfo = array();
        $contains = array('size' => 0, 'contain' => array(0, 0));
        foreach (DB::fetch_all("select * from %t where rid in(%n)", array($this->_table, $rids)) as $value) {
            $contains['size'] += $value['size'];
            if ($value['type'] == 'folder') {
                $contains['contain'][1] += 1;
                $containchild = '';
                $containchild = $this->get_contains_by_fid($value['oid'], true);
                if (!empty($containchild)) {
                    $contains['contain'][1] += $containchild['contain'][1];
                    $contains['contain'][0] += $containchild['contain'][0];
                    $contains['size'] += $containchild['size'];
                }

            } else {
                $contains['contain'][0] += 1;
            }
            if (!isset($fileinfo['path'])) {
                $path = C::t('resources_path')->fetch_pathby_pfid($value['pfid']);
                $fileinfo['position'] = preg_replace('/dzz:(.+?):/', '', $path);
            }

        }
        $fileinfo['ffsize'] = lang('property_info_size', array('fsize' => formatsize($contains['size']), 'size' => $contains['size']));
        $fileinfo['contain'] = lang('property_info_contain', array('filenum' => $contains['contain'][0], 'foldernum' => $contains['contain'][1]));
        $fileinfo['filenum'] = count($rids);
        return $fileinfo;
    }

    //查询目录文件数,$getversion =>是否获取版本数据
    public function get_contains_by_fid($fid, $getversion = true)
    {
        $contains = array('size' => 0, 'contain' => array(0, 0));
        $pfids = C::t('resources_path')->fetch_folder_containfid_by_pfid($fid);

        foreach (DB::fetch_all("select r.rid,r.vid,r.size as primarysize,r.type,r.pfid,v.size from %t r 
        left join %t v on r.rid=v.rid where r.pfid in (%n) and r.isdelete < 1", array($this->_table, 'resources_version', $pfids)) as $v) {
            if (!isset($resluts[$v['rid']])) {
                $resluts[$v['rid']] = $v;
            } else {
                $resluts[$v['rid']]['size'] += intval($v['size']);
            }
        }
        foreach ($resluts as $value) {
            if ($getversion) {
                $contains['size'] += ($value['size'] > 0) ? $value['size'] : $value['primarysize'];
            } else {
                $contains['size'] += $value['primarysize'];
            }
            if ($value['type'] == 'folder' && $fid != $value['oid']) {
                $contains['contain'][1] += 1;
            } else {
                $contains['contain'][0] += 1;
            }
        }
        return $contains;
    }

    //查询文件对应的rid
    public function fetch_rid_by_fid($fid)
    {
        return DB::result_first("select rid from %t where oid = %d and `type` = 'folder' ", array($this->_table, $fid));
    }

    //查询文件夹对应的fid
    public function fetch_fid_by_rid($rid)
    {
        return DB::result_first("select oid from %t where rid = %s and `type` = %s", array($this->_table, $rid, 'folder'));
    }

    //获取文件夹基本信息
    public function get_folderinfo_by_fid($fid)
    {
        if (!$folderinfo = C::t('folder')->fetch($fid)) return false;
        $contaions = self::get_contains_by_fid($fid, true);
        $folderinfo['ffsize'] = lang('property_info_size', array('fsize' => formatsize($contaions['size']), 'size' => $contaions['size']));
        $folderinfo['contain'] = lang('property_info_contain', array('filenum' => $contaions['contain'][0], 'foldernum' => $contaions['contain'][1]));
        $path = C::t('resources_path')->fetch_pathby_pfid($fid);
        $folderinfo['position'] = preg_replace('/dzz:(.+?):/', '', $path);
        $folderinfo['fdateline'] = dgmdate($folderinfo['dateline'], 'Y-m-d H:i:s');
        $folderinfo['isgroup'] = ($folderinfo['flag'] == 'organization') ? true : false;
        return $folderinfo;
    }

    //查詢文件夹下文件信息
    public function fetch_folderinfo_by_pfid($fid)
    {
        global $_G;
        if ($fid) {
            if ($folder = C::t('folder')->fetch($fid)) {
                $where1 = array();
                if ($folder['gid'] > 0) {
                    $folder['perm'] = perm_check::getPerm($folder['fid']);
                    if ($folder['perm'] > 0) {
                        if (perm_binPerm::havePower('read2', $folder['perm'])) {
                            //$where1[]="uid!='{$_G[uid]}'"; //原来查询思路，read2权限只能看到其他人建立文件，不能看到自己的
                            $where1[] = "1";
                        } elseif (perm_binPerm::havePower('read1', $folder['perm'])) {
                            $where1[] = "uid='{$_G[uid]}'";
                        }

                    }
                    $where1 = array_filter($where1);
                    if (!empty($where1)) $temp[] = "(" . implode(' OR ', $where1) . ")";
                    else $temp[] = "0";
                } else {
                    $temp[] = " uid='{$_G[uid]}'";
                }
                $where[] = '(' . implode(' and ', $temp) . ')';
                unset($temp);
            }
            $wheresql = "";
            if ($where) $wheresql .= implode(' AND ', $where);

            return DB::fetch_all("select * from %t where pfid = %d and isdelete = 0  and $wheresql", array($this->_table, $fid));
        }
    }

    public function fetch_all_by_pfid($pfid, $conditions=array(), $limit = 0, $orderby = '', $order = '', $start = 0, $count = false)
    {
        global $_G;
        $limitsql = $limit ? DB::limit($start, $limit) : '';
        $data = array();
        $wheresql = ' 1 ';
        $where = array();
        $para = array($this->_table);
        $where[] = ' isdelete < 1 ';
        $mustdition = '';
        //解析搜索条件
        if ($conditions && is_string($conditions)) {//字符串条件语句
            $wheresql .= $conditions;
        } elseif (is_array($conditions)) {
            foreach ($conditions as $k => $v) {
                if (!is_array($v)) {
                    if($k == 'mustdition'){
                        $mustdition = $v;
                    }else{
                        $connect = 'and';
                        $wheresql .= $connect . ' `' . $k . "` = '" . $v . "' ";
                    }
                } else {
                    $relative = isset($v[1]) ? $v[1] : '=';
                    $connect = isset($v[2]) ? $v[2] : 'and';
                    if ($relative == 'in') {
                        $wheresql .= $connect . "  `" . $k . "` " . $relative . " (" . dimplode($v[0]) . ") ";
                    } elseif ($relative == 'stringsql') {
                        $wheresql .= $connect . " " . $v[0] . " ";
                    } elseif ($relative == 'like') {
                        $wheresql .= $connect . " " . $k . " like %s ";
                        $para[] = '%' . $v[0] . '%';
                    } else {
                        $wheresql .= $connect . " `" . $k . "` " . $relative . " '". $v[0] . "' ";
                    }
                }
            }
        }
        if (is_array($pfid)) {
            $arr = array();
            foreach ($pfid as $fid) {
                $temp = array('pfid = %d');
                $para[] = $fid;
                if ($folder = C::t('folder')->fetch($fid)) {
                    $where1 = array();
                    if ($folder['gid'] > 0) {
                        $folder['perm'] = perm_check::getPerm($folder['fid']);
                        if ($folder['perm'] > 0) {
                            if (perm_binPerm::havePower('read2', $folder['perm'])) {
                                //$where1[]="uid!='{$_G[uid]}'"; //原来查询思路，read2只能查询其他人建立文件
                                $where1[] = "1";
                            } elseif (perm_binPerm::havePower('read1', $folder['perm'])) {
                                $where1[] = "uid='{$_G[uid]}'";
                            }

                        }
                        $where1 = array_filter($where1);
                        if (!empty($where1)) $temp[] = "(" . implode(' OR ', $where1) . ")";
                        else $temp[] = "0";
                    } else {
                        $temp[] = " uid='{$_G[uid]}'";
                    }
                }
                $arr[] = '(' . implode(' and ', $temp) . ')';
                unset($temp);
            }
            if ($arr) $where[] = '(' . implode(' OR ', $arr) . ')';
        } elseif ($pfid) {
            $temp = array('pfid= %d');
            $para[] = $pfid;
            if ($folder = C::t('folder')->fetch($pfid)) {
                $where1 = array();
                if ($folder['gid'] > 0) {
                    $folder['perm'] = perm_check::getPerm($folder['fid']);
                    if ($folder['perm'] > 0) {
                        if (perm_binPerm::havePower('read2', $folder['perm'])) {
                            //$where1[]="uid!='{$_G[uid]}'"; //原来查询思路，read2只能查询其他人建立文件
                            $where1[] = "1 = 1";
                        } elseif (perm_binPerm::havePower('read1', $folder['perm'])) {
                            $where1[] = "uid='{$_G[uid]}'";
                        }
                    }
                    $where1 = array_filter($where1);
                    if ($where1) $temp[] = "(" . implode(' OR ', $where1) . ")";
                    else $temp[] = "0";
                } else {
                    $temp[] = " uid='{$_G[uid]}'";
                }
            }
            $where[] = '(' . implode(' and ', $temp) . ')';
            unset($temp);
        }
        if($mustdition) $wheresql = '('.$wheresql .$mustdition.')';
        if ($where) $wheresql .= ' and ' . implode(' AND ', $where);
        if ($count) return DB::result_first("SELECT COUNT(*) FROM %t  $wheresql ", $para);
        $ordersql = '';
        if (is_array($orderby)) {
            foreach ($orderby as $key => $value) {
                $orderby[$key] = $value . ' ' . $order;
            }
            $ordersql = ' ORDER BY ' . implode(',', $orderby);
        } elseif ($orderby) {
            $ordersql = ' ORDER BY ' . $orderby . ' ' . $order;
        }
        foreach (DB::fetch_all("SELECT rid FROM %t where $wheresql $ordersql $limitsql", $para) as $value) {
            if ($arr = self::fetch_by_rid($value['rid'])) $data[$value['rid']] = $arr;
        }
        return $data;
    }

    //查询群组下的文件、文件夹信息
    public function fetch_all_by_gid($gid)
    {
        $gid = intval($gid);
        if ($folderinfo = C::t('folder')->fetch_folderinfo_by_gid($gid)) {
            return self::fetch_by_pfid($folderinfo['fid']);
        }
    }

    //查询目录下所有文件基本信息
    public function fetch_basicinfo_by_pfid($pfid)
    {
        $pfid = intval($pfid);
        return DB::fetch_all("select * from %t where pfid = %d", array($this->_table, $pfid));
    }

    //查询目录下的文件信息
    public function fetch_by_pfid($pfid, $uid = '',$checkperm = true)
    {
        $currentuid = getglobal('uid');
        $pfid = intval($pfid);
        $where = " pfid = %d";
        $param = array($this->_table, $pfid);
        $datainfo = array();
        if ($uid) {
            $where .= " and uid = %d";
            $param[] = $uid;
        }

        $path = C::t('resources_path')->fetch_pathby_pfid($pfid);
        foreach (DB::fetch_all("select * from %t  where $where and isdelete < 1 order by dateline desc", $param) as $k => $value) {
            if ($value['type'] == 'folder') {
                $value['path'] = $path . $value['name'] . '/';//路径
                if($checkperm){
                    if ($value['uid'] == $currentuid && !perm_check::checkperm_Container($value['oid'], 'read1')) {
                        continue;
                    } elseif (!perm_check::checkperm_Container($value['oid'], 'read2')) {
                        continue;
                    }
                }
            } else {
                if($checkperm){
                    $value['path'] = $path . $value['name'];//路径
                    if ($value['uid'] == $currentuid && !perm_check::checkperm_Container($value['pfid'], 'read1')) {
                        continue;
                    } elseif (!perm_check::checkperm_Container($value['pfid'], 'read2')) {
                        continue;
                    }
                }
            }
            $data = C::t('resources_attr')->fetch_by_rid($value['rid'], $value['vid']);
            $data['collect'] = C::t('resources_collect')->fetch_by_rid($value['rid']);
            $datainfo[$k] = $value;
            $datainfo[$k] = array_merge($datainfo[$k], $data);
            if (!isset($datainfo[$k]['img'])) {
                if ($datainfo[$k]['type'] == 'folder') {
                    $datainfo[$k]['img'] = '/dzz/images/extimg/folder.png';
                } elseif ($datainfo[$k]['type'] == 'image') {
                    $datainfo[$k]['img'] = DZZSCRIPT . '?mod=io&op=thumbnail&size=small&path=' . dzzencode('attach::' . $datainfo[$k]['aid']);
                } else {
                    $datainfo[$k]['img'] = '/dzz/images/extimg/' . $datainfo[$k]['ext'] . '.png';
                }
            }
            $datainfo[$k]['taginfo'] = C::t('resources_tag')->fetch_tag_by_rid($value['rid']);
            $datainfo[$k]['dpath'] = dzzencode($value['rid']);
            $datainfo[$k]['fsize'] = formatsize($value['size']);
        }
        return $datainfo;
    }

    public function update_by_rid($rid, $setarr)
    {
        if (DB::update($this->_table, $setarr, "rid = '{$rid}'")) {
            return true;
        } else {
            return false;
        }
    }

    //查询某目录下的所有文件夹
    public function fetch_folder_by_pfid($fid, $numselect = false)
    {

        return DB::fetch_all("select * from %t where pfid = %d and `type` = %s and isdelete < 1 ", array($this->_table, $fid, 'folder'));
    }

    public function fetch_folder_num_by_pfid($fid)
    {
        return DB::result_first("select count(*) from %t where pfid = %d and `type` = %s and deldateline < 1", array($this->_table, $fid, 'folder'));
    }

    public function get_property_by_rid($rids)
    {
        global $_G;
       // $uid = $_G['uid'];
        $wheresql = " where r.rid in(%n) ";
        $param = array($this->_table, 'folder', 'resources_path', $rids);
       // $orgids = C::t('organization')->fetch_all_orgid();//获取所有有管理权限的部门
       // $powerarr = perm_binPerm::getPowerArr();
        if (!is_array($rids)) $rids = (array)$rids;
       /* $or = array();
        //用户自己的文件；
        $or[] = "(r.gid=0 and r.uid=%d)";
        $param[] = $_G['uid'];
        //我管理的群组或部门的文件
        if ($orgids['orgids_admin'] && $_G['adminid']!=1) {
            $or[] = "r.gid IN (%n)";
            $param[] = $orgids['orgids_admin'];
        }
        //我参与的群组的文件
        if ($orgids['orgids_member']) {
            $or[] = "(r.gid IN(%n) and ((f.perm_inherit & %d) OR (r.uid=%d and f.perm_inherit & %d)))";
            $param[] = $orgids['orgids_member'];
            $param[] = $powerarr['read2'];
            $param[] = $_G['uid'];
            $param[] = $powerarr['read1'];
        }
        if ($or) $wheresql .= " and (" . implode(' OR ', $or) . ")";*/
        if (count($rids) > 1) {
            //获取文件基本信息
            $fileinfos = DB::fetch_all("select r.*,f.perm_inherit,p.path from %t  r left join %t f on r.pfid = f.fid left join %t p  on p.fid = r.pfid $wheresql", $param);
            if (!$fileinfos) {
                return array('error' => lang('no_privilege'));
            }
            $fileinfo = array();
            $tmpinfo = array();
            $infos = array();
            foreach ($fileinfos as $v) {
                $infos[$v['rid']] = $v;
                $tmpinfo['rids'][] = $v['rid'];
                $tmpinfo['names'][] = $v['name'];
                $tmpinfo['pfid'][] = $v['pfid'];
                $tmpinfo['ext'][] = ($v['ext']) ? $v['ext'] : $v['type'];
                $tmpinfo['type'][] = $v['type'];
                $tmpinfo['username'][] = $v['username'];
                $tnpinfo['hascontain'][$v['rid']] = ($v['type'] == 'folder') ? 1 : 0;
                $tmpinfo['realpath'][] = $v['path'];
            }
            $fileinfo['ismulti'] = count($rids);//是否是多选
            $fileinfo['name'] = getstr(implode(',', array_unique($tmpinfo['names'])), 60);
            //判断文件归属
            $fileinfo['username'] = (count(array_unique($tmpinfo['username'])) > 1) ? lang('more_member_owner') : $tmpinfo['username'][0];

            //判断是否在同一文件夹下
            if (count(array_unique($tmpinfo['pfid'])) > 1) {
                $fileinfo['realpath'] = lang('more_folder_position');
                $fileinfo['rid'] = implode(',', $tmpinfo['rids']);
            } else {
                $fileinfo['realpath'] = lang('all_positioned') . preg_replace('/dzz:(.+?):/', '', $tmpinfo['realpath'][0]);
                $fileinfo['rid'] = implode(',', $tmpinfo['rids']);
            }
            //判断文件类型是否相同
            $judgesecond = false;
            if (count(array_unique($tmpinfo['ext'])) > 1) {
                $fileinfo['type'] = lang('more_file_type');
                $judgesecond = true;

            } else {
                $fileinfo['type'] = lang('louis_vuitton') . $tmpinfo['ext'][0] . lang('type_of_file');
            }
            if (in_array('', $tmpinfo['ext']) || $judgesecond) {
                if (count(array_unique($tmpinfo['type'])) > 1) {
                    $fileinfo['type'] = lang('more_file_type');
                } else {
                    $fileinfo['type'] = lang('louis_vuitton') . $tmpinfo['type'][0] . lang('typename_attach');
                }
            }

            //文件大小和文件个数信息
            $tmpinfo['contains'] = array('size' => 0, 'contain' => array(0, 0));
            foreach ($tnpinfo['hascontain'] as $k => $v) {
                if ($v) {
                    $tmpinfo['contains']['contain'][1] += 1;
                    $childcontains = self::get_contains_by_fid($infos[$k]['oid'], true);
                    $tmpinfo['contains']['contain'][0] += $childcontains['contain'][0];
                    $tmpinfo['contains']['contain'][1] += $childcontains['contain'][1];
                    $tmpinfo['contains']['size'] += $childcontains['size'];
                } else {
                    $tmpinfo['contains']['contain'][0] += 1;
                    $tmpinfo['contains']['size'] += $infos[$k]['size'];
                }
            }
            $fileinfo['fsize'] = formatsize($tmpinfo['contains']['size']);
            $fileinfo['ffsize'] = lang('property_info_size', array('fsize' => formatsize($tmpinfo['contains']['size']), 'size' => $tmpinfo['contains']['size']));
            $fileinfo['contain'] = lang('property_info_contain', array('filenum' => $tmpinfo['contains']['contain'][0], 'foldernum' => $tmpinfo['contains']['contain'][1]));
            unset($tmpinfo);
        } else {//单个文件信息
            //文件基本信息
            $fileinfo =DB::fetch_first("select r.*,f.perm_inherit,p.path from %t r 
            left join %t f on r.pfid = f.fid left join %t p on r.pfid = p.fid $wheresql", $param);
			
            if (!$fileinfo) {
                return array('error' => lang('no_privilege'));
            }
            //文件统计信息
            $filestatis = C::t('resources_statis')->fetch_by_rid($rids[0]);
            //位置信息
			$fileinfo['realpath'] = preg_replace('/dzz:(.+?):/', '', $fileinfo['path']);
			
            
            //统计信息
            $fileinfo['opendateline'] = ($filestatis['opendateline']) ? dgmdate($filestatis['opendateline'], 'Y-m-d H:i:s') : dgmdate($fileinfo['dateline'], 'Y-m-d H:i:s');
            $fileinfo['editdateline'] = ($filestatis['editdateline']) ? dgmdate($filestatis['editdateline'], 'Y-m-d H:i:s') : dgmdate($fileinfo['dateline'], 'Y-m-d H:i:s');
            $fileinfo['fdateline'] = dgmdate($fileinfo['dateline'], 'Y-m-d H:i:s');
            //编辑权限信息
            $fileinfo['editperm'] = 1;
            if ($fileinfo['gid'] > 0) {
                if (!(C::t('organization_admin')->chk_memberperm($fileinfo['gid'])) && !($uid == $fileinfo['uid'] && $fileinfo['perm_inherit'] & $powerarr['edit1']) && !($fileinfo['perm_inherit'] & $powerarr['edit2'])) {
                    $fileinfo['editperm'] = 0;
                }
            }

            //文件图标信息
            $fileinfo['img'] = self::get_icosinfo_by_rid($fileinfo['rid']);
            //文件类型和大小信息
            if ($fileinfo['type'] == 'folder') {
                $fileinfo['type'] = '文件夹';
                if ($currentfolder = C::t('folder')->fetch($fileinfo['oid'])) {
                    $fileinfo['isgroup'] = ($currentfolder['flag'] == 'organization') ? true : false;
                }
                $contaions = self::get_contains_by_fid($fileinfo['oid'], true);
                $contaions['contain'][1] += 1;
                $fileinfo['fsize'] = formatsize($contaions['size']);
                $fileinfo['ffsize'] = lang('property_info_size', array('fsize' => formatsize($contaions['size']), 'size' => $contaions['size']));
                $fileinfo['contain'] = lang('property_info_contain', array('filenum' => $contaions['contain'][0], 'foldernum' => $contaions['contain'][1]));
            } elseif ($fileinfo['ext']) {
                $fileinfo['type'] = $fileinfo['ext'] . lang('typename_folder');
                $fileinfo['fsize'] = formatsize($fileinfo['size']);
            } else {
                $fileinfo['type'] = lang('undefined_file_type');
                $fileinfo['fsize'] = formatsize($fileinfo['size']);
            }

        }
        return $fileinfo;
    }

    public function get_property_by_fid($fid)
    {
        global $_G;
        $uid = $_G['uid'];
        $powerarr = perm_binPerm::getPowerArr();
        $fileinfo = array();
        $param = array('folder', 'resources_path', $fid, $powerarr['read2'], $uid, $powerarr['read1']);
        $folders = DB::fetch_first("select f.*,p.path from %t f left join %t p on f.fid = p.fid  
        where f.fid = %d and ((f.perm_inherit & %d) OR (f.uid=%d and f.perm_inherit & %d))", $param);
        if (!$folders) {
            return array('error' => lang('no_privilege'));
        }
        $fileinfo['realpath'] = preg_replace('/dzz:(.+?):/', '', $folders['path']);
        $fileinfo['name'] = $folders['fname'];
        $fileinfo['username'] = $folders['username'];
        if ($folders['gid'] > 0 && $folders['pfid'] == 0) {
            $fileinfo['type'] = lang('org_or_group');
        } else {
            $fileinfo['type'] = lang('type_folder');
        }
        $contaions = self::get_contains_by_fid($fid, true);
        $fileinfo['fsize'] = lang('property_info_size', array('fsize' => formatsize($contaions['size']), 'size' => $contaions['size']));
        $fileinfo['contain'] = lang('property_info_contain', array('filenum' => $contaions['contain'][0], 'foldernum' => $contaions['contain'][1]));
        //编辑权限信息
        if ($folders['pfid'] == 0 || (!($uid == $folders['uid'] && $folders['perm_inherit'] & $powerarr['edit1']) && !($folders['perm_inherit'] & $powerarr['edit2']))) {
            $fileinfo['editperm'] = 0;
        } else {
            $fileinfo['editperm'] = 1;
        }
        $statis = C::t('resources_statis')->fetch_by_fid($fid);
        $fileinfo['opendateline'] = ($statis['opendateline']) ? dgmdate($statis['opendateline'], 'Y-m-d H:i:s') : '';
        $fileinfo['editdateline'] = ($statis['editdateline']) ? dgmdate($statis['editdateline'], 'Y-m-d H:i:s') : '';
        $fileinfo['fdateline'] = ($folders['dateline']) ? dgmdate($folders['dateline'], 'Y-m-d H:i:s') : '';
        return $fileinfo;

    }

    public function get_icosinfo_by_rid($rid)
    {
        $resourcedata = parent::fetch($rid);
        $attrdata = C::t('resources_attr')->fetch_by_rid($rid, $resourcedata['vid']);
        $data = array_merge($resourcedata, $attrdata);
        if ($data['type'] == 'image') {
            $data['img'] = DZZSCRIPT . '?mod=io&op=thumbnail&size=small&path=' . dzzencode('attach::' . $data['aid']);
        } elseif ($data['type'] == 'attach' || $data['type'] == 'document') {
            $data['img'] = geticonfromext($data['ext'], $data['type']);
        } elseif ($data['type'] == 'shortcut') {
            $data['img'] = isset($data['tdata']['img']) ? $data['tdata']['img'] : geticonfromext($data['tdata']['ext'], $data['tdata']['type']);
        } elseif ($data['type'] == 'dzzdoc') {

            $data['img'] = isset($data['img']) ? $data['img'] : geticonfromext($data['ext'], $data['type']);
        } elseif ($data['type'] == 'folder') {
            $data['img'] = '/dzz/images/extimg/folder.png';
        } else {
            $data['img'] = isset($data['img']) ? $data['img'] : geticonfromext($data['ext'], $data['type']);
        }
        $img = $data['img'];
        unset($data);
        return $img;
    }

    //文件名获取文件信息
    public function get_resources_by_pfid_name($pfid, $name)
    {
        return DB::fetch_first("select * from %t where pfid=%d and name = %s and `type` = 'folder' ", array($this->_table, $pfid, $name));
    }

    //文件id获取文件信息
    public function get_resources_info_by_fid($fid)
    {
        return DB::fetch_first("select * from %t where oid = %d and `type` = 'folder' ", array($this->_table, $fid));
    }
}