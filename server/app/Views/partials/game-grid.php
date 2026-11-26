<?php foreach ($games as $g): ?><?= partial('game-card', ['g' => $g, 'favIds' => $favIds ?? []]) ?><?php endforeach; ?>
