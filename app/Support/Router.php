<?php
namespace App\Support;

class Router
{
  private $routes = [];
  private $groups = [];
  private $currentGroupPrefix = '';
  private $currentGroupMiddleware = [];

  public function get($path, $handler){ $this->map('GET', $path, $handler); }
  public function post($path, $handler){ $this->map('POST', $path, $handler); }
  public function put($path, $handler){ $this->map('PUT', $path, $handler); }
  public function patch($path, $handler){ $this->map('PATCH', $path, $handler); }
  public function delete($path, $handler){ $this->map('DELETE', $path, $handler); }

  public function group($prefix, $fn, $middleware = null) {
    $prevPrefix = $this->currentGroupPrefix;
    $prevMw = $this->currentGroupMiddleware;

    $this->currentGroupPrefix .= rtrim($prefix, '/');
    if ($middleware) $this->currentGroupMiddleware[] = $middleware;
    $fn($this);
    $this->currentGroupPrefix = $prevPrefix;
    $this->currentGroupMiddleware = $prevMw;
  }

  private function map($method, $path, $handler) {
    $full = $this->currentGroupPrefix . $path;
    $this->routes[] = [
      'method'=>$method,
      'path'=>$full,
      'handler'=>$handler,
      'mw'=>$this->currentGroupMiddleware
    ];
  }

  public function dispatch(){
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    foreach ($this->routes as $r){
      if ($r['method'] !== $method) continue;
      $params = [];
      $pattern = preg_replace('#\{[^/]+\}#', '([^/]+)', $r['path']);
      $pattern = '#^'.rtrim($pattern,'/').'$#';
      if (preg_match($pattern, rtrim($uri,'/'), $m)) {
        array_shift($m);
        // extract param names
        preg_match_all('#\{([^/]+)\}#', $r['path'], $names);
        $names = $names[1];
        foreach ($names as $i=>$name) $params[$name] = $m[$i];

        // Middleware chain (each can modify $request-like array)
        $req = ['params'=>$params,'body'=>Util::jsonBody(),'headers'=>getallheaders()];
        foreach ($r['mw'] as $mw) {
          $res = $mw->handle($req);
          if ($res && isset($res['status'])) {
            http_response_code($res['status']);
            foreach ($res['headers'] ?? [] as $k=>$v) header("$k: $v");
            echo json_encode($res['body'] ?? []);
            return;
          }
          // middleware can also set attributes (e.g., uid)
          $req = $mw->request() ?: $req;
        }

        $handler = $r['handler'];
        if (is_array($handler)) {
          $class = $handler[0]; $method = $handler[1];
          $obj = new $class;
          $out = $obj->$method($req);
        } else {
          $out = call_user_func($handler, $req);
        }
        if ($out === null) return;
        http_response_code($out['status'] ?? 200);
        foreach (($out['headers'] ?? []) as $k=>$v) header("$k: $v");
        echo json_encode($out['body'] ?? []);
        return;
      }
    }

    http_response_code(404);
    echo json_encode(['error'=>'not_found']);
  }
}
