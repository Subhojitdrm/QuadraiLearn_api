<?php
namespace App\Controllers;
use App\Support\DB;
use App\Support\Response;

class ChaptersController {
  public function show($req){
    $uid = (int)$req['uid']; $id = (int)$req['params']['id'];
    $pdo = DB::pdo();
    $sel = $pdo->prepare("SELECT c.* FROM chapters c JOIN books b ON b.id=c.book_id WHERE c.id=? AND b.user_id=?");
    $sel->execute([$id,$uid]); $c = $sel->fetch();
    if (!$c) return Response::json(['error'=>'not_found'],404);
    return Response::json($c);
  }
  public function pages($req){
    $uid = (int)$req['uid']; $id = (int)$req['params']['id'];
    $pdo = DB::pdo();
    $chk = $pdo->prepare("SELECT 1 FROM chapters c JOIN books b ON b.id=c.book_id WHERE c.id=? AND b.user_id=?");
    $chk->execute([$id,$uid]); if (!$chk->fetch()) return Response::json(['error'=>'not_found'],404);
    $rows = $pdo->prepare("SELECT page_number, content FROM chapter_pages WHERE chapter_id=? ORDER BY page_number");
    $rows->execute([$id]);
    return Response::json($rows->fetchAll());
  }
}
