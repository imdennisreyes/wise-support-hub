<?php

final class AdminController
{
    public static function route(string $path): void
    {
        require_role('Admin', 'Editor');
        if ($path === '/reports') { self::reports(); return; }
        if ($path === '/admin') { self::dashboard(); return; }
        if ($path === '/admin/articles') { self::articles(); return; }
        if ($path === '/admin/articles/create') { self::articleForm(); return; }
        if (preg_match('#^/admin/articles/(\d+)/edit$#', $path, $m)) { self::articleForm((int)$m[1]); return; }
        if (preg_match('#^/admin/articles/(\d+)/archive$#', $path, $m) && method_is('POST')) { self::archiveArticle((int)$m[1]); return; }
        if ($path === '/admin/users') { self::users(); return; }
        if ($path === '/admin/users/create') { self::userForm(); return; }
        if (preg_match('#^/admin/users/(\d+)/edit$#', $path, $m)) { self::userForm((int)$m[1]); return; }
        if ($path === '/admin/taxonomies') { self::taxonomies(); return; }
        if (preg_match('#^/admin/taxonomies/([a-z]+)/archive$#', $path, $m) && method_is('POST')) { self::archiveTaxonomy($m[1]); return; }
        if ($path === '/admin/import') { self::import(); return; }
        if ($path === '/admin/doc-import') { self::docImport(); return; }
        if ($path === '/admin/confluence-sync') { self::confluenceSync(); return; }
        if ($path === '/admin/confluence-sync/run' && method_is('POST')) { self::confluenceSyncRun(); return; }
        http_response_code(404);
        render('error', ['title' => 'Admin page not found', 'message' => 'The requested administration page does not exist.']);
    }

    private static function dashboard(): void
    {
        if (!has_role('Admin')) redirect('/admin/articles');
        $pdo = Database::connection();
        $stats = [
            'total' => 'SELECT COUNT(*) FROM knowledge_articles WHERE deleted_at IS NULL',
            'sops' => "SELECT COUNT(*) FROM knowledge_articles WHERE type='SOP' AND deleted_at IS NULL",
            'errors' => "SELECT COUNT(*) FROM knowledge_articles WHERE type='Error' AND deleted_at IS NULL",
            'requests' => "SELECT COUNT(*) FROM knowledge_articles WHERE type='Request' AND deleted_at IS NULL",
            'published' => "SELECT COUNT(*) FROM knowledge_articles WHERE status='Published' AND deleted_at IS NULL",
            'drafts' => "SELECT COUNT(*) FROM knowledge_articles WHERE status='Draft' AND deleted_at IS NULL",
            'review' => "SELECT COUNT(*) FROM knowledge_articles WHERE status='Under Review' AND deleted_at IS NULL",
        ];
        foreach ($stats as $key => $query) $stats[$key] = (int)$pdo->query($query)->fetchColumn();
        $recent = $pdo->query(Article::baseSelect() . ' WHERE a.deleted_at IS NULL ORDER BY a.updated_at DESC LIMIT 8')->fetchAll();
        $topSearches = $pdo->query('SELECT query, COUNT(*) AS searches, SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS gaps FROM search_logs GROUP BY query ORDER BY searches DESC LIMIT 8')->fetchAll();
        $feedback = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN is_helpful = 1 THEN 1 ELSE 0 END) AS helpful FROM article_feedback')->fetch();
        render('admin/dashboard', ['title' => 'Administration', 'stats' => $stats, 'recent' => $recent, 'topSearches' => $topSearches, 'feedbackStats' => $feedback]);
    }

    private static function articles(): void
    {
        $filters = $_GET;
        $articles = Article::browse($filters, true);
        render('admin/articles', ['title' => 'Article Manager', 'articles' => $articles, 'systems' => Taxonomy::all('systems'), 'categories' => Taxonomy::all('categories')]);
    }

    private static function articleForm(?int $id = null): void
    {
        $article = $id ? Article::find($id) : null;
        if ($id && !$article) { http_response_code(404); render('error', ['title' => 'Article not found', 'message' => 'The article cannot be edited.']); return; }
        $errors = [];
        if (method_is('POST')) {
            verify_csrf();
            $data = $_POST;
            $allowed = [
                'type' => ['Article','Error','SOP','Request'], 'severity' => ['','Critical','High','Medium','Low'],
                'status' => ['Draft','Published','Under Review','Archived'],
            ];
            if (!in_array($data['type'] ?? '', $allowed['type'], true)) $errors[] = 'Select a valid content type.';
            if (!in_array($data['severity'] ?? '', $allowed['severity'], true)) $errors[] = 'Select a valid severity.';
            if (!in_array($data['status'] ?? '', $allowed['status'], true)) $errors[] = 'Select a valid status.';
            if (trim($data['title'] ?? '') === '') $errors[] = 'Title is required.';
            if (trim($data['description'] ?? '') === '') $errors[] = 'Description is required.';
            if (($data['type'] ?? '') === 'SOP' && trim($data['sop_steps'] ?? '') === '') $errors[] = 'SOP steps are required for SOP records.';
            if (!$errors) {
                $savedId = Article::save($data, $id, current_user()['id']);
                flash('success', $id ? 'Article updated and version recorded.' : 'Article created and version recorded.');
                redirect('/admin/articles/' . $savedId . '/edit');
            }
            $article = array_merge($article ?? [], $data);
        }
        $pdo = Database::connection();
        $users = $pdo->query("SELECT id,name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
        $allArticles = $pdo->query("SELECT id,title,type FROM knowledge_articles WHERE deleted_at IS NULL" . ($id ? ' AND id <> ' . (int)$id : '') . ' ORDER BY title')->fetchAll();
        $selectedRelated = $id ? array_column(Article::related($id), 'id') : [];
        render('admin/article_form', [
            'title' => $id ? 'Edit Article' : 'New Article', 'article' => $article, 'errors' => $errors,
            'systems' => Taxonomy::all('systems'), 'modules' => Taxonomy::all('modules'), 'categories' => Taxonomy::all('categories'),
            'carriers' => Taxonomy::all('carriers'), 'users' => $users, 'allArticles' => $allArticles, 'selectedRelated' => $selectedRelated,
        ]);
    }

    private static function archiveArticle(int $id): void
    {
        verify_csrf();
        if (!Article::find($id)) { flash('danger', 'Article not found.'); redirect('/admin/articles'); }
        Article::archive($id, current_user()['id']);
        flash('success', 'Article archived. Its version history has been retained.');
        redirect('/admin/articles');
    }

    private static function users(): void
    {
        require_role('Admin');
        $users = Database::connection()->query('SELECT u.id,u.name,u.email,u.is_active,u.last_login_at,u.created_at,r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.name')->fetchAll();
        render('admin/users', ['title' => 'User Management', 'users' => $users]);
    }

    private static function userForm(?int $id = null): void
    {
        require_role('Admin');
        $pdo = Database::connection();
        $user = null;
        if ($id) {
            $stmt = $pdo->prepare('SELECT id,name,email,role_id,is_active FROM users WHERE id=?'); $stmt->execute([$id]); $user = $stmt->fetch();
            if (!$user) { http_response_code(404); render('error', ['title'=>'User not found','message'=>'This user account does not exist.']); return; }
        }
        $errors = [];
        if (method_is('POST')) {
            verify_csrf();
            $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $roleId = (int)($_POST['role_id'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0; $password = (string)($_POST['password'] ?? '');
            if ($name === '') $errors[] = 'Name is required.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
            $roleCheck = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE id=?'); $roleCheck->execute([$roleId]); if (!$roleCheck->fetchColumn()) $errors[] = 'Select a valid role.';
            if (!$id && strlen($password) < 12) $errors[] = 'New users require a password of at least 12 characters.';
            if ($password !== '' && strlen($password) < 12) $errors[] = 'Reset passwords must be at least 12 characters.';
            if ($id === current_user()['id'] && !$active) $errors[] = 'You cannot deactivate your own account.';
            $duplicate = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email)=LOWER(?) AND id <> ?'); $duplicate->execute([$email, $id ?? 0]); if ($duplicate->fetchColumn()) $errors[] = 'That email address is already in use.';
            if (!$errors) {
                if ($id) {
                    $params = [$roleId,$name,$email,$active];
                    $sql = 'UPDATE users SET role_id=?,name=?,email=?,is_active=?,updated_at=CURRENT_TIMESTAMP';
                    if ($password !== '') { $sql .= ',password_hash=?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
                    $sql .= ' WHERE id=?'; $params[] = $id; $pdo->prepare($sql)->execute($params);
                } else {
                    $pdo->prepare('INSERT INTO users (role_id,name,email,password_hash,is_active,created_at,updated_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')->execute([$roleId,$name,$email,password_hash($password,PASSWORD_DEFAULT),$active]);
                }
                flash('success', $id ? 'User account updated.' : 'User account created.'); redirect('/admin/users');
            }
            $user = ['id'=>$id,'name'=>$name,'email'=>$email,'role_id'=>$roleId,'is_active'=>$active];
        }
        $roles = $pdo->query('SELECT id,name,description FROM roles ORDER BY id')->fetchAll();
        render('admin/user_form', ['title'=>$id?'Edit User':'New User','editUser'=>$user,'roles'=>$roles,'errors'=>$errors]);
    }

    private static function taxonomies(): void
    {
        require_role('Admin');
        $allowed = ['systems','carriers','categories','tags'];
        if (method_is('POST')) {
            verify_csrf(); $table = $_POST['taxonomy'] ?? ''; $name = trim($_POST['name'] ?? ''); $code = trim($_POST['code'] ?? '');
            if (!in_array($table, $allowed, true) || $name === '') { flash('danger','Choose a taxonomy and provide a name.'); redirect('/admin/taxonomies'); }
            try {
                $stmt = Database::connection()->prepare("INSERT INTO {$table} (name,code,is_active,created_at,updated_at) VALUES (?,?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
                $stmt->execute([$name,$code ?: null]); flash('success', ucfirst(rtrim($table,'s')) . ' created.');
            } catch (PDOException) { flash('danger','That taxonomy name already exists.'); }
            redirect('/admin/taxonomies?tab=' . $table);
        }
        $data=[]; foreach ($allowed as $table) $data[$table]=Taxonomy::all($table,false);
        render('admin/taxonomies', ['title'=>'Taxonomy Management','taxonomies'=>$data,'activeTab'=>in_array($_GET['tab']??'', $allowed,true)?$_GET['tab']:'systems']);
    }

    private static function archiveTaxonomy(string $table): void
    {
        require_role('Admin'); verify_csrf();
        if (!in_array($table,['systems','carriers','categories','tags'],true)) { http_response_code(400); exit('Invalid taxonomy.'); }
        $id=(int)($_POST['id']??0); Database::connection()->prepare("UPDATE {$table} SET is_active=0,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
        flash('success','Taxonomy entry archived. Existing article links remain intact.'); redirect('/admin/taxonomies?tab='.$table);
    }

    private static function import(): void
    {
        require_role('Admin');
        $errors=[]; $preview=$_SESSION['csv_preview']??[];
        if (method_is('POST')) {
            verify_csrf(); $action=$_POST['action']??'preview';
            if ($action==='preview') {
                $preview=[]; unset($_SESSION['csv_preview']);
                if (!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK) $errors[]='Choose a CSV file to preview.';
                elseif ($_FILES['csv']['size'] > 2*1024*1024) $errors[]='CSV files may not exceed 2 MB.';
                else {
                    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['csv']['tmp_name']);
                    if (!in_array($mime,['text/plain','text/csv','application/csv','application/vnd.ms-excel'],true)) $errors[]='The uploaded file is not a recognized text CSV.';
                    else [$preview,$errors]=self::parseCsv($_FILES['csv']['tmp_name']);
                }
                if (!$errors) $_SESSION['csv_preview']=$preview;
            } elseif ($action==='import') {
                $preview=$_SESSION['csv_preview']??[]; if (!$preview) $errors[]='The import preview expired. Upload the CSV again.';
                if (!$errors) {
                    $imported=0; $failed=[];
                    foreach ($preview as $number=>$row) {
                        try {
                            $systemId=Taxonomy::findOrCreate('systems',$row['system']); $categoryId=Taxonomy::findOrCreate('categories',$row['category']); $carrierId=Taxonomy::findOrCreate('carriers',$row['carrier']);
                            Article::save([
                                'type'=>$row['type']?:'Article','title'=>$row['title'],'system_id'=>$systemId,'category_id'=>$categoryId,'carrier_id'=>$carrierId,
                                'error_code'=>$row['error_code'],'error_message'=>$row['error_message'],'description'=>$row['error_message']?:$row['title'],
                                'root_cause'=>$row['cause'],'resolution'=>$row['resolution'],'keywords'=>$row['keywords'],'severity'=>$row['severity']?:'Low',
                                'status'=>$row['status']?:'Draft','owner_id'=>current_user()['id'],'is_sample'=>0,'change_summary'=>'Created by CSV importer',
                            ],null,current_user()['id']); $imported++;
                        } catch (Throwable $e) { $failed[]='Row '.($number+2).': '.$e->getMessage(); }
                    }
                    unset($_SESSION['csv_preview']); $preview=[];
                    flash($failed?'warning':'success',"Imported {$imported} article(s); " . count($failed) . ' failed.');
                    if ($failed) $_SESSION['import_failures']=array_slice($failed,0,20);
                    redirect('/admin/import');
                }
            }
        }
        $failures=$_SESSION['import_failures']??[]; unset($_SESSION['import_failures']);
        render('admin/import',['title'=>'CSV Import','preview'=>$preview,'errors'=>$errors,'failures'=>$failures]);
    }

    private static function parseCsv(string $path): array
    {
        $handle=fopen($path,'rb'); if (!$handle) return [[],['Unable to read the uploaded CSV.']];
        $required=['title','system','category','carrier','error_code','error_message','cause','resolution','keywords'];
        $optional=['type','severity','status']; $headers=array_map(fn($v)=>strtolower(trim((string)$v)),fgetcsv($handle)?:[]);
        $missing=array_diff($required,$headers); if ($missing) { fclose($handle); return [[],['Missing required columns: '.implode(', ',$missing)]]; }
        $rows=[]; $errors=[]; $line=1;
        while (($values=fgetcsv($handle))!==false) {
            $line++; if (count(array_filter($values,fn($v)=>trim((string)$v)!==''))===0) continue;
            $values=array_pad($values,count($headers),''); $row=array_combine($headers,array_slice($values,0,count($headers)));
            foreach ($optional as $field) if (!isset($row[$field])) $row[$field]='';
            if (trim($row['title'])==='') { $errors[]="Row {$line}: title is required."; continue; }
            if ($row['type']!==''&&!in_array($row['type'],['Article','Error','SOP','Request'],true)) { $errors[]="Row {$line}: invalid type."; continue; }
            if ($row['severity']!==''&&!in_array($row['severity'],['Critical','High','Medium','Low'],true)) { $errors[]="Row {$line}: invalid severity."; continue; }
            if ($row['status']!==''&&!in_array($row['status'],['Draft','Published','Under Review'],true)) { $errors[]="Row {$line}: invalid status."; continue; }
            $rows[] = array_map(fn($v)=>mb_substr(trim((string)$v),0,10000),$row);
            if (count($rows)>=500) { $errors[]='Imports are limited to 500 data rows.'; break; }
        }
        fclose($handle); return [$rows,$errors];
    }

    private static function reports(): void
    {
        require_role('Admin'); $pdo=Database::connection();
        $searches=$pdo->query('SELECT query,COUNT(*) AS searches,MAX(created_at) AS last_searched,SUM(CASE WHEN result_count=0 THEN 1 ELSE 0 END) AS zero_results,ROUND(AVG(result_count),1) AS avg_results FROM search_logs GROUP BY query ORDER BY searches DESC,last_searched DESC LIMIT 100')->fetchAll();
        $feedback=$pdo->query('SELECT a.id,a.title,COUNT(f.id) AS responses,SUM(CASE WHEN f.is_helpful=1 THEN 1 ELSE 0 END) AS helpful,SUM(CASE WHEN f.is_helpful=0 THEN 1 ELSE 0 END) AS unhelpful FROM knowledge_articles a JOIN article_feedback f ON f.article_id=a.id GROUP BY a.id,a.title ORDER BY unhelpful DESC,responses DESC')->fetchAll();
        $comments=$pdo->query('SELECT f.reason,f.created_at,a.id AS article_id,a.title,u.name AS user_name FROM article_feedback f JOIN knowledge_articles a ON a.id=f.article_id LEFT JOIN users u ON u.id=f.user_id WHERE f.is_helpful=0 AND f.reason IS NOT NULL ORDER BY f.created_at DESC LIMIT 50')->fetchAll();
        render('admin/reports',['title'=>'Reports','searches'=>$searches,'feedback'=>$feedback,'comments'=>$comments]);
    }

    // ── Document Import ───────────────────────────────────────────────────────

    private static function docImport(): void
    {
        require_role('Admin');
        $errors = [];

        if (method_is('POST')) {
            verify_csrf();

            $fileError = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($fileError !== UPLOAD_ERR_OK) {
                $errors[] = $fileError === UPLOAD_ERR_NO_FILE
                    ? 'Choose a Word (.docx) or PDF file to import.'
                    : 'File upload failed (error code ' . $fileError . '). Try again.';
            } elseif ($_FILES['document']['size'] > 16 * 1024 * 1024) {
                $errors[] = 'File may not exceed 16 MB.';
            } else {
                $file     = $_FILES['document'];
                $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $mime     = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $okMimes  = [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/pdf',
                    'application/octet-stream',   // some browsers send this for .docx
                    'application/zip',            // also seen for .docx
                ];
                $okExts = ['docx', 'pdf'];

                if (!in_array($ext, $okExts, true) || (!in_array($mime, $okMimes, true) && !in_array($ext, $okExts, true))) {
                    $errors[] = 'Only Word (.docx) and PDF files are supported.';
                } else {
                    $type = in_array($_POST['type'] ?? '', ['Article', 'Error', 'SOP', 'Request'], true)
                        ? $_POST['type']
                        : 'Article';

                    try {
                        $parsed    = DocumentParserService::parse($file['tmp_name'], $file['name']);
                        $articleId = Article::save([
                            'type'           => $type,
                            'title'          => $parsed['title'],
                            'description'    => $parsed['description'],
                            'status'         => 'Draft',
                            'owner_id'       => current_user()['id'],
                            'is_sample'      => 0,
                            'change_summary' => 'Imported from document: ' . $parsed['filename'],
                        ], null, current_user()['id']);

                        // Store the original file binary in the DB so it can be downloaded later
                        $fileData = file_get_contents($file['tmp_name']);
                        if ($fileData !== false) {
                            $stmt = Database::connection()->prepare(
                                'UPDATE knowledge_articles SET source_document_name = ?, source_document_data = ? WHERE id = ?'
                            );
                            $stmt->execute([$file['name'], $fileData, $articleId]);
                        }

                        flash('success', 'Document imported as a Draft article. Review the content and publish when ready.');
                        redirect('/admin/articles/' . $articleId . '/edit');
                    } catch (RuntimeException $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }
        }

        render('admin/doc_import', ['title' => 'Document Import', 'errors' => $errors]);
    }

    // ── Confluence Sync ───────────────────────────────────────────────────────

    private static function confluenceSync(): void
    {
        require_role('Admin');
        render('admin/confluence_sync', ['title' => 'Confluence Sync']);
    }

    private static function confluenceSyncRun(): void
    {
        require_role('Admin');
        verify_csrf();

        header('Content-Type: application/json; charset=utf-8');

        $translate = !empty($_POST['translate']);
        $actorId   = current_user()['id'] ?? 1;

        try {
            $service = new ConfluenceSyncService();
            $result  = $service->sync($translate, (int) $actorId);
            echo json_encode($result);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'imported' => 0,
                'updated'  => 0,
                'skipped'  => 0,
                'errors'   => [$e->getMessage()],
                'log'      => [['status' => 'error', 'message' => $e->getMessage()]],
            ]);
        }

        exit;
    }
}
