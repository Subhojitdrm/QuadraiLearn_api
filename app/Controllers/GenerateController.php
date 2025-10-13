<?php
namespace App\Controllers;
use App\Support\DB;
use App\Support\Response;
use App\Support\Util;

class BooksController {
  public function index($req){
    $uid = (int)($req['uid'] ?? 0);
    $pdo = DB::pdo();
    $rows = $pdo->prepare("SELECT * FROM books WHERE user_id=? ORDER BY updated_at DESC");
    $rows->execute([$uid]);
    return Response::json(['books'=>$rows->fetchAll()]);
  }
  public function create($req){
    $uid = (int)$req['uid'];
    $b = $req['body'];
    $title = Util::str($b['title'] ?? 'Untitled');
    $topic = Util::str($b['topic'] ?? '');
    $style = Util::str($b['style'] ?? 'Technical');
    $level = Util::str($b['level'] ?? 'Beginner');
    $lang  = Util::str($b['language'] ?? '');
    $count = (int)($b['chapter_count'] ?? 24);
    if (!$topic) return Response::error('topic_required', 422);

    $pdo = DB::pdo();
    $ins = $pdo->prepare("INSERT INTO books(user_id,title,topic,style,level,language,chapter_count,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?, 'draft', NOW(), NOW())");
    $ins->execute([$uid,$title,$topic,$style,$level,$lang,$count]);
    return Response::json(['id'=>(int)$pdo->lastInsertId()]);
  }
  public function show($req){
    $uid = (int)$req['uid']; $id = (int)$req['params']['id'];
    $pdo = DB::pdo();
    $sel = $pdo->prepare("SELECT * FROM books WHERE id=? AND user_id=? LIMIT 1");
    $sel->execute([$id,$uid]);
    $b = $sel->fetch(); if (!$b) return Response::error('not_found',404);
    $chs = $pdo->prepare("SELECT id,chapter_number,title,status FROM chapters WHERE book_id=? ORDER BY chapter_number");
    $chs->execute([$id]);
    $b['chapters'] = $chs->fetchAll();
    return Response::json($b);
  }
  public function destroy($req){
    $uid = (int)$req['uid']; $id = (int)$req['params']['id'];
    $pdo = DB::pdo();
    $pdo->prepare("DELETE FROM books WHERE id=? AND user_id=?")->execute([$id,$uid]);
    return Response::json(['ok'=>true]);
  }
}
