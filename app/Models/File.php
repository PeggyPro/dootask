<?php

namespace App\Models;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class File
 *
 * @package App\Models
 * @property int $id
 * @property int|null $pid 上级ID
 * @property int|null $cid 复制ID
 * @property string|null $name 名称
 * @property string|null $type 类型
 * @property int|null $size 大小(B)
 * @property int|null $userid 拥有者ID
 * @property int|null $share 是否共享(1:共享所有人,2:指定成员)
 * @property int|null $created_id 创建者ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder|File newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|File newQuery()
 * @method static \Illuminate\Database\Query\Builder|File onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|File query()
 * @method static \Illuminate\Database\Eloquent\Builder|File whereCid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereCreatedId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File wherePid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereShare($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|File whereUserid($value)
 * @method static \Illuminate\Database\Query\Builder|File withTrashed()
 * @method static \Illuminate\Database\Query\Builder|File withoutTrashed()
 * @mixin \Eloquent
 */
class File extends AbstractModel
{
    use SoftDeletes;

    /**
     * 是否有访问权限
     *  ① 自己的目录
     *  ② 共享所有人的目录
     *  ③ 在指定共享人员内
     * @param $userid
     */
    public function chackAllow($userid)
    {
        if ($userid == $this->userid) {
            // ① 自己的目录
            return;
        }
        $row = $this->getShareInfo();
        if ($row) {
            if ($row->share == 1) {
                // ② 共享所有人的目录
                return;
            } elseif ($row->share == 2) {
                // ③ 在指定共享人员内
                if (FileUser::whereFileId($row->id)->whereUserid($userid)->exists()) {
                    return;
                }
            }
        }
        throw new ApiException('没有访问权限');
    }

    /**
     * 获取共享数据（含自身）
     * @return $this|null
     */
    public function getShareInfo()
    {
        if ($this->share > 0) {
            return $this;
        }
        $pid = $this->pid;
        while ($pid > 0) {
            $row = self::whereId($pid)->first();
            if (empty($row)) {
                break;
            }
            if ($row->share > 0) {
                return $row;
            }
            $pid = $row->pid;
        }
        return null;
    }

    /**
     * 是否处于共享目录内（不含自身）
     * @return bool
     */
    public function isNnShare()
    {
        $pid = $this->pid;
        while ($pid > 0) {
            $row = self::whereId($pid)->first();
            if (empty($row)) {
                break;
            }
            if ($row->share > 0) {
                return true;
            }
            $pid = $row->pid;
        }
        return false;
    }

    /**
     * 设置/关闭 共享（同时遍历取消里面的共享）
     * @param $share
     * @return bool
     */
    public function setShare($share)
    {
        AbstractModel::transaction(function () use ($share) {
            $this->share = $share;
            $this->save();
            $list = self::wherePid($this->id)->get();
            if ($list->isNotEmpty()) {
                foreach ($list as $item) {
                    $item->setShare(0);
                }
            }
        });
        return true;
    }

    /**
     * 遍历删除文件(夹)
     * @return bool
     */
    public function deleteFile()
    {
        AbstractModel::transaction(function () {
            $this->delete();
            FileContent::whereFid($this->id)->delete();
            $list = self::wherePid($this->id)->get();
            if ($list->isNotEmpty()) {
                foreach ($list as $item) {
                    $item->deleteFile();
                }
            }
        });
        return true;
    }

    /**
     * 获取文件并检测权限
     * @param $id
     * @param null $noExistTis
     * @return File
     */
    public static function allowFind($id, $noExistTis = null)
    {
        $file = File::find($id);
        if (empty($file)) {
            throw new ApiException($noExistTis ?: '文件不存在或已被删除');
        }
        $file->chackAllow(User::userid());
        return $file;
    }
}
