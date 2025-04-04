# 事件

模型事件實現 [psr/event-dispatcher](https://github.com/php-fig/event-dispatcher) 接口，默認由 [hyperf/event](https://github.com/hyperf/event) 組件提供功能支持。

## 運行事件

在 ORM 的運行期間，會對應觸發以下事件，您可以進行對這些事件進行監聽以滿足您的需求。

| 事件  | 描述 |
| :--------: | :----: |
| Hyperf\Database\Events\QueryExecuted| Query 語句執行後 |
| Hyperf\Database\Events\StatementPrepared| SQL 語句 prepared 後 |
| Hyperf\Database\Events\TransactionBeginning| 事務開啓後 |
| Hyperf\Database\Events\TransactionCommitted| 事務提交後 |
| Hyperf\Database\Events\TransactionRolledBack| 事務回滾後 |

### SQL 執行監聽器

根據上述的 ORM 運行事件，接下來我們來實現一個記錄 SQL 的監聽器，達到在每次執行 SQL 時記錄下來並輸出到日誌上。
首先我們定義好 `DbQueryExecutedListener` ，實現 `Hyperf\Event\Contract\ListenerInterface` 接口並對類定義 `Hyperf\Event\Annotation\Listener` 註解，這樣 Hyperf 就會在應用啓動時自動把該監聽器註冊到事件調度器中，並在事件觸發時執行監聽邏輯，示例代碼如下：

```php
<?php

declare(strict_types=1);

namespace App\Listener;

use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Collection\Arr;
use Hyperf\Stringable\Str;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[Listener]
class DbQueryExecutedListener implements ListenerInterface
{
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        // 輸出到對應名為 sql 的日誌 name，如不存在則需自行添加配置
        // 這裏的 sql 日誌 name 不是必須的，只是表達可以將 SQL 執行日誌與普通日誌區分開
        $this->logger = $container->get(LoggerFactory::class)->get('sql');
    }

    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    /**
     * @param QueryExecuted $event
     */
    public function process(object $event)
    {
        if ($event instanceof QueryExecuted) {
            $sql = $event->sql;
            if (! Arr::isAssoc($event->bindings)) {
                foreach ($event->bindings as $key => $value) {
                    $sql = Str::replaceFirst('?', "'{$value}'", $sql);
                }
            }

            $this->logger->info(sprintf('[%s] %s', $event->time, $sql));
        }
    }
}

```

## 模型事件

模型事件與 `Eloquent ORM` 不太一致，`Eloquent ORM` 使用 `Observer` 監聽模型事件。`Hyperf` 提供 `鈎子函數` 和 `事件監聽` 兩種形式來處理對應的事件。

### 鈎子函數

|    事件名    |     觸發實際     | 是否阻斷 |               備註                |
|:------------:|:----------------:|:--------:|:-------------------------- --:|
|   booting    |  模型首次加載前  |    否    |    進程生命週期中只會觸發一次         |
|    booted    |  模型首次加載後  |    否    |    進程生命週期中只會觸發一次         |
|  retrieved   |    填充數據後   |    否    |  每當模型從 DB 或緩存查詢出來後觸發      |
|   creating   |    數據創建時   |    是    |                                  |
|   created    |    數據創建後   |    否    |                                  |
|   updating   |    數據更新時   |    是    |                                  |
|   updated    |    數據更新後   |    否    |                                  |
|    saving    | 數據創建或更新時 |    是    |                                  |
|    saved     | 數據創建或更新後 |    否    |                                  |
|  restoring   | 軟刪除數據恢復時 |    是    |                                  |
|   restored   | 軟刪除數據恢復後 |    否    |                                  |
|   deleting   |    數據刪除時   |    是    |                                  |
|   deleted    |    數據刪除後   |    否    |                                  |
| forceDeleting |  數據強制刪除時  |    是    |                                  |
| forceDeleted |  數據強制刪除後  |    否    |                                  |

針對某個模型的事件使用十分簡單，只需要在模型中增加對應的方法即可。例如下方保存數據時，觸發 `saving` 事件，主動覆寫 `created_at` 字段。

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hyperf\Database\Model\Events\Saving;

/**
 * @property $id
 * @property $name
 * @property $gender
 * @property $created_at
 * @property $updated_at
 */
class User extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['id', 'name', 'gender', 'created_at', 'updated_at'];

    protected $casts = ['id' => 'integer', 'gender' => 'integer'];

    public function saving(Saving $event)
    {
        $this->setCreatedAt('2019-01-01');
    }
}

```

### 事件監聽

當您需要監聽所有的模型事件時，可以很方便的自定義對應的 `Listener`，比如下方模型緩存的監聽器，當模型修改和刪除後，會刪除對應緩存。

```php
<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Listener;

use Hyperf\Database\Model\Events\Deleted;
use Hyperf\Database\Model\Events\Event;
use Hyperf\Database\Model\Events\Saved;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\ModelCache\CacheableInterface;

#[Listener]
class DeleteCacheListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            Deleted::class,
            Saved::class,
        ];
    }

    public function process(object $event)
    {
        if ($event instanceof Event) {
            $model = $event->getModel();
            if ($model instanceof CacheableInterface) {
                $model->deleteCache();
            }
        }
    }
}

```

### 觀察者

得益於 [hyperf/model-listener](https://github.com/hyperf/blob/master/src/model-listener/) 組件，我們也可以使用 `Observer` 來監聽模型事件。
通過 [ModelListener](https://github.com/hyperf/hyperf/blob/master/src/model-listener/src/Annotation/ModelListener.php) 註解，我們可以很方便的定義一個觀察者，示例代碼如下：

```php
<?php
use Hyperf\ModelListener\Annotation\ModelListener;
use App\Model\User;
use Hyperf\Database\Model\Events\Creating;
use Hyperf\Database\Model\Events\Created;

/**
 * 定義一個 UserObserver 觀察者，監聽 User 模型的事件.
 * 也可以監聽多個模型，只需要在 models 屬性中傳入多個模型即可
 * 需要注意，此類將會被自動註冊到容器中成為單例
 */
#[ModelListener(models: [ User::class ])]
class UserObserver
{
    public function creating(Creating $event)
    {
        $user = $event->getModel();
        // 創建用户時觸發
    }
    
    public function created(Created $event)
    {
        $user = $event->getModel();
        // 用户創建完成後觸發
    }
    
    //... 省略其他事件
}
```

