<?php

function changelogInsert(string $object, int $object_id, string $action, array $data, ?int $user_id): void {
    R::exec("
        INSERT INTO changelog (user_id, object, object_id, action, data)
        VALUES (:user_id, :object, :object_id, :action, :data)
    ", [
        ':user_id'   => $user_id,
        ':object'    => $object,
        ':object_id' => $object_id,
        ':action'    => $action,
        ':data'      => json_encode($data),
    ]);
}
