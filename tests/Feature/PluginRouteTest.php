<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Plugin;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PluginRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // ExtendedPlanプラグインが既にインストールされているか確認し、削除する
        if (Plugin::where('class', 'App\\Plugins\\ExtendedPlan\\ExtendedPlan')->exists()) {
            $this->get('/plugins/ExtendedPlan/uninstall');
        }
        
        // アプリケーションをリフレッシュしてルートとビューを初期化
        $this->refreshApplication();
    }

    public function test_can_access_plugin_route_after_installation(): void
    {
        // プラグインのインストール前はルートにアクセスできない
        $response = $this->get('/extendedPlan');
        $response->assertStatus(404);

        // プラグインをインストール（リダイレクト予定）
        $installResponse = $this->get('/plugins/ExtendedPlan/install');
        $installResponse->assertStatus(302); // プラグイン一覧ページへリダイレクト
        $installResponse->assertRedirect('/plugins'); // リダイレクトURLを確認

        // プラグインのインストール後はルートにアクセス可能
        $response = $this->get('/extendedPlan');
        $response->assertStatus(200);
        $response->assertViewIs('extendedPlan::index');
        $response->assertSee('service value:extended'); // ビューに含まれるテキストを確認

        // プラグインがDBに正しく登録されているか確認
        $this->assertDatabaseHas('plugins', [
            'class' => 'App\\Plugins\\ExtendedPlan\\ExtendedPlan',
            'active' => true
        ]);
    }

    public function test_plugin_route_not_accessible_after_uninstall(): void
    {
        // まずプラグインをインストール
        $this->get('/plugins/ExtendedPlan/install');
        
        // インストール直後はルートにアクセス可能
        $response = $this->get('/extendedPlan');
        $response->assertStatus(200);

        // プラグインを削除
        $uninstallResponse = $this->get('/plugins/ExtendedPlan/uninstall');
        $uninstallResponse->assertStatus(302); // プラグイン一覧ページへリダイレクト

        // ルートキャッシュを初期化
        $this->refreshApplication();

        // 削除後はルートにアクセスできない
        $response = $this->get('/extendedPlan');
        $response->assertStatus(404);

        // プラグインが無効化されているか確認
        $this->assertDatabaseHas('plugins', [
            'class' => 'App\\Plugins\\ExtendedPlan\\ExtendedPlan',
            'active' => false
        ]);
    }
} 