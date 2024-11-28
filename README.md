# プラグインシステム仕様書

## 概要

このプラグインシステムは、アプリケーションの機能を動的に拡張するためのフレームワークを提供します。

## プラグインインターフェース

すべてのプラグインは `PluginInterface` を実装する必要があります：

```php
interface PluginInterface
{
    public function register(): void;   // サービスの登録
    public function boot(): void;       // ルートやビューの起動
    public function unregister(): void; // サービスの登録解除
}
```

### register()

サービスの登録は、下記の手順で行います。
```php
// 既存のサービスを削除
app()->forgetInstance(PlanInterface::class);
app()->offsetUnset(PlanInterface::class);

// 新しいサービスを登録
app()->bind(PlanInterface::class, ExtendedPlanService::class);
```

### boot()

ルートやビューの起動は、下記の手順で行います。
```php
// プラグイン名をプレフィックスにしたルートグループを作成
Route::middleware('web')
    ->prefix('/extendedPlan')
    ->name('extendedPlan.')
    ->group(__DIR__ . '/routes/web.php');

// ビューの読み込み
view()->addNamespace('extendedPlan', __DIR__ . '/views');
```

### unregister()

サービスの登録解除は、下記の手順で行います。
```php
// 新しいサービスを削除
app()->forgetInstance(PlanInterface::class);
app()->offsetUnset(PlanInterface::class);

// 既存のサービスに戻す
app()->bind(PlanInterface::class, SimplePlanService::class);

// ルートやビューの登録を解除
$router = app('router');
$routes = $router->getRoutes();

$newRouteCollection = new \Illuminate\Routing\RouteCollection();
foreach ($routes->getRoutes() as $route) {
    if (!str_starts_with($route->uri(), 'extendedPlan')) {
        $newRouteCollection->add($route);
    }
}

$router->setRoutes($newRouteCollection);

$viewFactory = app('view');
app()->forgetInstance('view');
app()->instance('view', $viewFactory->getFinder()->flush());

```

### PluginHelper

プラグイン内で共通して使用するメソッドをまとめたトレイトです。
register, boot, unregister で下記のように使用します。

```php
use PluginHelper;

const NS = 'extendedPlan';

public function register(): void
{
    $this->registerService(PlanInterface::class, ExtendedPlanService::class);
    Log::debug('ExtendedPlan registered');
}

public function boot(): void
{
    $this->registerRoute(self::NS, self::NS, __DIR__ . '/routes/web.php');

    $this->registerView(self::NS, __DIR__ . '/views');
    Log::debug('ExtendedPlan booted');
}

public function unregister(): void
{
    $this->unregisterRouteAndView(self::NS);
    $this->registerService(PlanInterface::class, SimplePlanService::class);
    Log::debug('ExtendedPlan unregistered');
}
```

## プラグインマネージャーの主要機能

### 1. プラグインの読み込み

`loadPlugins()` メソッド:

-   `app/Plugins` ディレクトリ内のプラグインを検索
-   プラグインクラスをインスタンス化
-   アクティブなプラグインのみを読み込み


### 2. プラグインのインストール

`installPlugin()` メソッド:

-   プラグインの登録
-   マイグレーションの実行
-   サービスの登録
-   ルートとビューの読み込み


### 3. プラグインのアンインストール

`uninstallPlugin()` メソッド:

-   サービスの登録解除
-   マイグレーションのロールバック
-   プラグインの非アクティブ化

## サービスの登録と上書き

### サービスバインディング

```php
app()->bind(PlanInterface::class, ExtendedPlanService::class);
```

このコードにより、既存のサービスが新しいプラグインのサービスで上書きされます。

### マイグレーション

-   プラグインディレクトリ内の `migrations` フォルダに配置
-   `PluginManager` が自動的に検出して実行
-   ロールバック機能も提供

### ルートとビュー

-   プラグイン固有の名前空間でビューを登録
-   プレフィックス付きのルートグループを使用
-   例：`extendedPlan::index` でビューにアクセス

## 動作の流れ

1. プラグインのインストール

    - データベースにプラグイン情報を登録
    - マイグレーションを実行

2. プラグインの起動

    - サービスの登録（register）
    - ルートとビューの読み込み（boot）

3. プラグインの使用

    - 依存性注入によるサービスの利用
    - 名前空間付きビューの表示
    - プレフィックス付きルートへのアクセス

4. プラグインの無効化
    - サービスの登録解除
    - マイグレーションのロールバック
    - プラグイン情報の更新

## 注意事項

-   プラグインの命名規則を厳守すること
-   マイグレーションは必ずロールバック可能な形で作成すること
-   サービスの上書き時は既存機能への影響を考慮すること
