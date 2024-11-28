<style>
    .alert {
        margin: 10px 0;
    }
    .alert-danger {
        color: red;
    }
    .container {
        margin: 10px 0;
        background-color: #f0f0f0;
        padding: 10px;
        border-radius: 5px;

        li {
            display: flex;
            flex-direction: row;
            margin: 0 10px;
        }

        button {
            margin: 0 5px;
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
        }
    }
</style>
<h1>Plugins</h1>
@if(session('result'))
    <div class="alert alert-danger">{{ session('result') }}</div>
@endif
@if(isset($value))
    <div class="alert alert-success">
        <h3>Override Services</h3>
        <p>PlanInterface->getPlan(): <b>{{ $value }}</b></p>
    </div>
@endif
@if(isset($columns))
    <div class="alert alert-success">
        <h3>Migrate DB</h3>
        <p>DB::getSchemaBuilder()->getColumnListing('plans'):</p>
        <ul>
            @foreach($columns as $column)
                <li><b>{{ $column }}</b></li>
            @endforeach
        </ul>
    </div>
@endif
@if(isset($routes))
    <div class="alert alert-success">
        <h3>Routes</h3>
        <p>Route::getRoutes():</p>
        <ul>
            @foreach($routes as $route)
            @php
                $action = $route->action;
                $controller = $action['controller'] ?? $route->controller;
                $uri = $route->uri;
                $str = $uri . ' ' . $controller;
            @endphp
                <li><b>{{ $str }}</b></li>
            @endforeach
        </ul>
    </div>
@endif
<div class="container">
    <p>Plugins:</p>
    <ul>
        @foreach($plugins as $plugin)
        @php
            $route = $plugin->active ? 'plugins.uninstall' : 'plugins.install';
        @endphp
        <li>
            {{ $plugin->class }}
            <form action="{{ route($route, $plugin->class) }}" method="GET">
                @csrf
                <button type="submit">{{ $plugin->active ? 'Uninstall' : 'Install' }}</button>
            </form>
            </li>
        @endforeach
    </ul>
</div>
