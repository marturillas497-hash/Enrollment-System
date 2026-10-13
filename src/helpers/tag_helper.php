<?php
/**
 * Tags link subjects to the teachers who can teach them. A tag is a short topic name such as
 * "Programming" or "Anatomy". Names are unique ignoring capitals.
 */

function normalizeTagName(string $name): string
{
    return trim((string)preg_replace('/\s+/', ' ', $name));
}

/** Every tag with how many subjects and teachers use it, A to Z. */
function allTags(PDO $pdo): array
{
    return $pdo->query(
        'SELECT t.tag_id, t.tag_name,
                (SELECT COUNT(*) FROM Subject_tag st WHERE st.tag_id = t.tag_id) AS subject_count,
                (SELECT COUNT(*) FROM Teacher_tag tt WHERE tt.tag_id = t.tag_id) AS teacher_count
         FROM Tag t ORDER BY t.tag_name'
    )->fetchAll();
}

/** "Programming, Databases" becomes clean unique names. Returns the names, or an error message. */
function parseNewTags(string $csv): array|string
{
    $names = [];
    foreach (explode(',', $csv) as $raw) {
        $name = normalizeTagName($raw);
        if ($name === '') {
            continue;
        }
        if (mb_strlen($name) > 50) {
            return 'A tag name can be at most 50 characters.';
        }
        $names[mb_strtolower($name)] = $name;
    }
    if (count($names) > 10) {
        return 'Add at most 10 new tags at a time.';
    }
    return array_values($names);
}

/**
 * Ids for the ticked tags plus any new names, which are created when missing.
 * Call inside a transaction. Ticked ids that no longer exist are dropped.
 */
function resolveTagIds(PDO $pdo, array $tickedIds, array $newNames): array
{
    $ids = [];
    $tickedIds = array_values(array_unique(array_filter(array_map('intval', $tickedIds), fn($v) => $v > 0)));
    if ($tickedIds) {
        $in = implode(',', array_fill(0, count($tickedIds), '?'));
        $stmt = $pdo->prepare("SELECT tag_id FROM Tag WHERE tag_id IN ($in)");
        $stmt->execute($tickedIds);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $find = $pdo->prepare('SELECT tag_id FROM Tag WHERE LOWER(tag_name) = LOWER(:n)');
    $add = $pdo->prepare('INSERT INTO Tag (tag_name) VALUES (:n)');
    foreach ($newNames as $name) {
        $find->execute(['n' => $name]);
        $existing = $find->fetchColumn();
        if ($existing !== false) {
            $ids[] = (int)$existing;
        } else {
            $add->execute(['n' => $name]);
            $ids[] = (int)$pdo->lastInsertId();
        }
    }
    return array_values(array_unique($ids));
}

function replaceSubjectTags(PDO $pdo, int $subjectId, array $tagIds): void
{
    $pdo->prepare('DELETE FROM Subject_tag WHERE subject_id = :id')->execute(['id' => $subjectId]);
    $ins = $pdo->prepare('INSERT INTO Subject_tag (subject_id, tag_id) VALUES (:s, :t)');
    foreach ($tagIds as $tagId) {
        $ins->execute(['s' => $subjectId, 't' => $tagId]);
    }
}

function replaceTeacherTags(PDO $pdo, int $teacherId, array $tagIds): void
{
    $pdo->prepare('DELETE FROM Teacher_tag WHERE teacher_id = :id')->execute(['id' => $teacherId]);
    $ins = $pdo->prepare('INSERT INTO Teacher_tag (teacher_id, tag_id) VALUES (:t, :g)');
    foreach ($tagIds as $tagId) {
        $ins->execute(['t' => $teacherId, 'g' => $tagId]);
    }
}

/**
 * How well a teacher fits a subject. 'full' means the teacher has every tag of the subject, 'partial' at
 * least one, 'none' no shared tag, and 'untagged' means the subject has no tags so nobody can be matched.
 */
function tagMatchLevel(array $subjectTagIds, array $teacherTagIds): string
{
    if (!$subjectTagIds) {
        return 'untagged';
    }
    $shared = count(array_intersect($subjectTagIds, $teacherTagIds));
    if ($shared === count($subjectTagIds)) {
        return 'full';
    }
    return $shared > 0 ? 'partial' : 'none';
}

/** Checkbox list of existing tags plus a box for new ones. Posts tag_ids[] and new_tags. */
function renderTagPicker(array $tags, array $selectedIds, string $newValue = ''): string
{
    $selected = array_map('strval', $selectedIds);
    $html = '<div class="border rounded p-2 mb-2" style="max-height: 150px; overflow-y: auto;">';
    if (!$tags) {
        $html .= '<span class="text-muted small">No tags yet. Add some below.</span>';
    }
    foreach ($tags as $t) {
        $id = (int)$t['tag_id'];
        $html .= '<div class="form-check form-check-inline"><input class="form-check-input tag-check" type="checkbox" name="tag_ids[]" value="' . $id . '" id="tag' . $id . '"'
            . (in_array((string)$id, $selected, true) ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="tag' . $id . '">' . htmlspecialchars($t['tag_name']) . '</label></div>';
    }
    $html .= '</div>';
    $html .= '<input class="form-control" name="new_tags" id="newTags" placeholder="New tags, separated by commas" value="' . htmlspecialchars($newValue) . '">';
    return $html;
}
