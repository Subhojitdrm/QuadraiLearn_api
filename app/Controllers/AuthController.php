<?php
namespace App\Controllers;
use App\Support\DB;
use App\Support\Response;
use App\Support\Util;
use App\Support\JWT;
use PDO;

class AuthController {
  public function register($req){
    $b = $req['body'];
    $firstName = Util::str($b['firstName'] ?? '');
    $lastName  = Util::str($b['lastName'] ?? '');
    $username  = Util::str($b['username'] ?? '');
    $email     = Util::str($b['email'] ?? '');
    $pass      = (string)($b['password'] ?? '');
    $studyNeed = Util::str($b['primaryStudyNeed'] ?? null);
    $interests = is_array($b['interestedAreas']) ? $b['interestedAreas'] : [];

    if (!$firstName || !$lastName || !$username || !$email || !$pass) {
      return Response::error('missing_fields', 422);
    }

    $pdo = DB::pdo();
    
    // Check for duplicate email first
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email=?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) return Response::error('email_exists', 409);

    // Check for duplicate username and suggest a new one if it exists
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username=?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
      $suggestion = $username . rand(100, 999);
      // Ensure suggestion is also unique
      $stmt->execute([$suggestion]);
      while($stmt->fetch()){
        $suggestion = $username . rand(100, 999);
        $stmt->execute([$suggestion]);
      }
      return Response::error('username_exists', 409, ['suggestion' => $suggestion]);
    }

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $ins = $pdo->prepare("INSERT INTO users(first_name,last_name,username,email,pass_hash,primary_study_need,created_at) VALUES(?,?,?,?,?,?,NOW())");
    $ins->execute([$firstName, $lastName, $username, $email, $hash, $studyNeed]);
    $uid = (int)$pdo->lastInsertId();

    $this->handleInterests($pdo, $uid, $interests);

    $token = JWT::issue($uid, 3600);
    return Response::json(['token'=>$token,'user'=>['id'=>$uid,'username'=>$username,'email'=>$email, 'firstName'=>$firstName, 'lastName'=>$lastName]]);
  }

  public function login($req){
    $b = $req['body'];
    $userOrEmail = Util::str($b['usernameOrEmail'] ?? '');
    $pass        = (string)($b['password'] ?? '');
    if (!$userOrEmail || !$pass) return Response::error('missing_fields', 422);

    $pdo = DB::pdo();
    $sel = $pdo->prepare("SELECT * FROM users WHERE username=? OR email=? LIMIT 1");
    $sel->execute([$userOrEmail, $userOrEmail]);
    $u = $sel->fetch();
    if (!$u || !password_verify($pass, $u['pass_hash'])) return Response::error('invalid_credentials', 401);

    $token = JWT::issue((int)$u['id'], 3600);
    return Response::json(['token'=>$token,'user'=>['id'=>(int)$u['id'],'username'=>$u['username'],'email'=>$u['email']]]);
  }

  private function handleInterests($pdo, $userId, $interestNames) {
    if (empty($interestNames)) return;

    $placeholders = rtrim(str_repeat('?,', count($interestNames)), ',');
    
    // Find or create interests
    $findOrCreateStmt = $pdo->prepare("INSERT INTO interests (name) VALUES (?) ON DUPLICATE KEY UPDATE name=name");
    foreach ($interestNames as $name) {
        $findOrCreateStmt->execute([trim($name)]);
    }

    // Get IDs of all interests
    $getIdsStmt = $pdo->prepare("SELECT id FROM interests WHERE name IN ($placeholders)");
    $getIdsStmt->execute($interestNames);
    $interestIds = $getIdsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Link interests to user
    $linkStmt = $pdo->prepare("INSERT IGNORE INTO user_interests (user_id, interest_id) VALUES (?, ?)");
    foreach ($interestIds as $interestId) {
        $linkStmt->execute([$userId, $interestId]);
    }
  }
}
