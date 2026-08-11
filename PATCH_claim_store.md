# Patch für claim_store.php

## 1) Oben ergänzen

Nach den require_once-Zeilen:

```php
require_once __DIR__ . '/step_template_helper.php';
```

## 2) Stelle suchen, an der claim_steps erzeugt werden

Suche den Block, der D1-D8 fest als Array anlegt und dann in `claim_steps` schreibt.

Ersetze diesen Block durch:

```php
$d2Content = (string)($problem_description ?? $problemDescription ?? $description ?? '');

create_claim_steps_from_templates(
    $db,
    (int)$claimId,
    (int)($user['id'] ?? 0),
    $d2Content
);
```

Falls deine PDO-Variable `$pdo` heißt:

```php
create_claim_steps_from_templates(
    $pdo,
    (int)$claimId,
    (int)($user['id'] ?? 0),
    $d2Content
);
```

## Ergebnis

Neue manuelle Reklamationen nutzen ab dann die aktive Vorlage aus `step_templates`.
