<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
require __DIR__ . '/db_connect.php';
require __DIR__ . '/includes/functions.php';

$pageTitle = 'Room Type Management';
$errors=[];
$msg='';
$uploadDir=__DIR__.'/uploads/room-types';
if(!is_dir($uploadDir)) mkdir($uploadDir,0755,true);

try {
    $isMysql = defined('DB_DRIVER') && DB_DRIVER === 'mysql';
    $isPgsql = defined('DB_DRIVER') && DB_DRIVER === 'pgsql';
    $rtdPk = $isPgsql ? 'id SERIAL PRIMARY KEY' : ($isMysql ? 'id INT AUTO_INCREMENT PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT');
    $rtdUpdated = $isPgsql ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : ($isMysql ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TEXT DEFAULT CURRENT_TIMESTAMP');
    $pdo->exec("CREATE TABLE IF NOT EXISTS room_type_details (
      $rtdPk,
      room_type VARCHAR(150) NOT NULL UNIQUE,
      description TEXT NULL,
      photo VARCHAR(255) NULL,
      ac INTEGER NOT NULL DEFAULT 0,
      wifi INTEGER NOT NULL DEFAULT 0,
      tv INTEGER NOT NULL DEFAULT 0,
      breakfast INTEGER NOT NULL DEFAULT 0,
      updated_at $rtdUpdated
    )");
} catch(Throwable $e) { $errors[]=$e->getMessage(); }

if($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    try {
        if($action==='add') {
            $name=trim($_POST['type_name']??'');
            $total=max(0,(int)($_POST['total_rooms']??0));
            $rate=max(0,(float)($_POST['default_rate']??0));
            if($name==='') throw new Exception('Room type name is required.');
            // Prevent duplicate room-type names (the database column is UNIQUE).
            $st=$pdo->prepare('SELECT COUNT(*) FROM room_types WHERE LOWER(TRIM(type_name)) = LOWER(TRIM(?))');
            $st->execute([$name]);
            if((int)$st->fetchColumn()>0) throw new Exception('Room type already exists. Please choose a different name.');
            $st=$pdo->prepare('INSERT INTO room_types(type_name,total_rooms,default_rate) VALUES(?,?,?)');
            $st->execute([$name,$total,$rate]);
            saveDetails($pdo,$name,$_POST,$uploadDir,null);
            header('Location: room-types.php?added=1'); exit;
        }
        if($action==='update') {
            $id=(int)($_POST['id']??0); $name=trim($_POST['type_name']??'');
            $total=max(0,(int)($_POST['total_rooms']??0)); $rate=max(0,(float)($_POST['default_rate']??0));
            if($name==='') throw new Exception('Room type name is required.');
            // Prevent renaming a room type to another existing name.
            $st=$pdo->prepare('SELECT COUNT(*) FROM room_types WHERE LOWER(TRIM(type_name)) = LOWER(TRIM(?)) AND id <> ?');
            $st->execute([$name,$id]);
            if((int)$st->fetchColumn()>0) throw new Exception('Another room type already uses that name. Please choose a different name.');
            $st=$pdo->prepare('SELECT type_name FROM room_types WHERE id=?'); $st->execute([$id]); $old=$st->fetchColumn();
            if($old===false) throw new Exception('Room type not found.');
            $pdo->beginTransaction();
            $st=$pdo->prepare('UPDATE room_types SET type_name=?,total_rooms=?,default_rate=? WHERE id=?'); $st->execute([$name,$total,$rate,$id]);
            if($old!==$name) $pdo->prepare('UPDATE hotel_rooms SET room_type=? WHERE room_type=?')->execute([$name,$old]);
            $pdo->commit();
            saveDetails($pdo,$name,$_POST,$uploadDir,$old);
            header('Location: room-types.php?updated=1'); exit;
        }
        if($action==='delete_photo') {
            $name=trim($_POST['type_name']??'');
            $st=$pdo->prepare('SELECT photo FROM room_type_details WHERE room_type=?'); $st->execute([$name]); $photo=$st->fetchColumn();
            if($photo && is_file($uploadDir.'/'.$photo)) @unlink($uploadDir.'/'.$photo);
            $pdo->prepare('UPDATE room_type_details SET photo=NULL WHERE room_type=?')->execute([$name]);
            header('Location: room-types.php?photo_removed=1'); exit;
        }
        if($action==='delete') {
            $id=(int)($_POST['id']??0);
            $st=$pdo->prepare('SELECT type_name FROM room_types WHERE id=?'); $st->execute([$id]); $name=$st->fetchColumn();
            if($name===false) throw new Exception('Room type not found.');
            // Room types are master-data records and can be deleted even when
            // historical bookings exist. Bookings store the room type as text,
            // so deleting the current room type does not delete or alter history.
            $st=$pdo->prepare('SELECT photo FROM room_type_details WHERE room_type=?'); $st->execute([$name]); $photo=$st->fetchColumn();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM hotel_rooms WHERE room_type=?')->execute([$name]);
            $pdo->prepare('DELETE FROM room_types WHERE id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM room_type_details WHERE room_type=?')->execute([$name]);
            $pdo->commit();
            if($photo && is_file($uploadDir.'/'.$photo)) @unlink($uploadDir.'/'.$photo);
            header('Location: room-types.php?deleted=1'); exit;
        }
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $errors[]=$e->getMessage();
    }
}

function saveDetails(PDO $pdo,string $name,array $post,string $uploadDir,?string $oldName): void {
    $desc=trim($post['description']??'');
    $ac=isset($post['ac'])?1:0; $wifi=isset($post['wifi'])?1:0; $tv=isset($post['tv'])?1:0; $breakfast=isset($post['breakfast'])?1:0;
    $photo=null;
    if(isset($_FILES['photo']) && $_FILES['photo']['error']===UPLOAD_ERR_OK) {
        $ext=strtolower(pathinfo($_FILES['photo']['name'],PATHINFO_EXTENSION));
        if(!in_array($ext,['jpg','jpeg','png','webp'],true)) throw new Exception('Photo must be JPG, PNG or WEBP.');
        $photo='roomtype_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        if(!move_uploaded_file($_FILES['photo']['tmp_name'],$uploadDir.'/'.$photo)) throw new Exception('Could not save the room photo.');
    }
    $lookup=$oldName??$name;
    $st=$pdo->prepare('SELECT photo FROM room_type_details WHERE room_type=?'); $st->execute([$lookup]); $oldPhoto=$st->fetchColumn();
    if($oldName && $oldName!==$name) $pdo->prepare('DELETE FROM room_type_details WHERE room_type=?')->execute([$oldName]);
    if($photo) {
        $st=$pdo->prepare('INSERT INTO room_type_details(room_type,description,photo,ac,wifi,tv,breakfast) VALUES(?,?,?,?,?,?,?) ON CONFLICT(room_type) DO UPDATE SET description=excluded.description,photo=excluded.photo,ac=excluded.ac,wifi=excluded.wifi,tv=excluded.tv,breakfast=excluded.breakfast');
        $st->execute([$name,$desc,$photo,$ac,$wifi,$tv,$breakfast]);
        if($oldPhoto && is_file($uploadDir.'/'.$oldPhoto)) @unlink($uploadDir.'/'.$oldPhoto);
    } else {
        $st=$pdo->prepare('INSERT INTO room_type_details(room_type,description,ac,wifi,tv,breakfast) VALUES(?,?,?,?,?,?) ON CONFLICT(room_type) DO UPDATE SET description=excluded.description,ac=excluded.ac,wifi=excluded.wifi,tv=excluded.tv,breakfast=excluded.breakfast');
        $st->execute([$name,$desc,$ac,$wifi,$tv,$breakfast]);
    }
}

$rows=$pdo->query('SELECT r.*,d.description,d.photo,d.ac,d.wifi,d.tv,d.breakfast FROM room_types r LEFT JOIN room_type_details d ON d.room_type=r.type_name ORDER BY r.type_name')->fetchAll(PDO::FETCH_ASSOC);
require __DIR__ . '/includes/header.php';
?>
<h1>Room Type Management</h1>
<?php foreach(['added'=>'Room type added.','updated'=>'Room type updated.','deleted'=>'Room type deleted.','photo_removed'=>'Photo removed.'] as $k=>$v): ?><?php if(isset($_GET[$k])): ?><div class="alert alert-success"><?=h($v)?></div><?php endif; ?><?php endforeach; ?>
<?php if($errors): ?><div class="alert alert-error"><ul><?php foreach($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><h3>Add Room Type</h3><form method="post" enctype="multipart/form-data" class="form-row"><input type="hidden" name="action" value="add"><div class="form-group"><label>Room Type</label><input name="type_name" required></div><div class="form-group"><label>Total Rooms</label><input type="number" name="total_rooms" min="0" value="0" required></div><div class="form-group"><label>Price / Night</label><input type="number" step="0.01" min="0" name="default_rate" value="0"></div><div class="form-group"><label>Description</label><textarea name="description" rows="2"></textarea></div><div class="form-group"><label>Amenities</label><div><label><input type="checkbox" name="ac"> AC</label> <label><input type="checkbox" name="wifi"> Wi-Fi</label> <label><input type="checkbox" name="tv"> TV</label> <label><input type="checkbox" name="breakfast"> Breakfast</label></div></div><div class="form-group"><label>Photo</label><input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"></div><button class="btn btn-accent" type="submit">Add Room Type</button></form></div>
<div class="card"><h3>Existing Room Types</h3><div style="overflow-x:auto"><table><tr><th>Photo</th><th>Type</th><th>Total</th><th>Price</th><th>Description & Amenities</th><th>Actions</th></tr>
<?php foreach($rows as $r): ?><tr><form method="post" enctype="multipart/form-data"><input type="hidden" name="id" value="<?=h((string)$r['id'])?>"><td><?php if($r['photo']): ?><img src="uploads/room-types/<?=h($r['photo'])?>" style="width:90px;height:60px;object-fit:cover;border-radius:6px" alt="Room photo"><?php else: ?>No photo<?php endif; ?></td><td><input name="type_name" value="<?=h($r['type_name'])?>" required></td><td><input type="number" name="total_rooms" min="0" value="<?=h((string)$r['total_rooms'])?>"></td><td><input type="number" step="0.01" min="0" name="default_rate" value="<?=h((string)$r['default_rate'])?>"></td><td><textarea name="description" rows="3" style="min-width:220px"><?=h($r['description']??'')?></textarea><div><label><input type="checkbox" name="ac" <?=$r['ac']?'checked':''?>> AC</label> <label><input type="checkbox" name="wifi" <?=$r['wifi']?'checked':''?>> Wi-Fi</label> <label><input type="checkbox" name="tv" <?=$r['tv']?'checked':''?>> TV</label> <label><input type="checkbox" name="breakfast" <?=$r['breakfast']?'checked':''?>> Breakfast</label></div></td><td><input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"><button class="btn" name="action" value="update">Update</button><?php if($r['photo']): ?><button class="btn" name="action" value="delete_photo">Remove Photo</button><?php endif; ?><button class="btn" name="action" value="delete" onclick="return confirm('Delete this room type?')">Delete</button></td></form></tr><?php endforeach; ?></table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
