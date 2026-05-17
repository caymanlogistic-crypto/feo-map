# MAP PROJECT — CODEX WORKFLOW RULES

## PROJECT STATUS

Проект успешно прошёл:
- архитектурный рефакторинг;
- runtime stabilization;
- FTP runtime testing;
- transport visibility filtering;
- planned/found route panels;
- edit-mode implementation;
- production-safe separation.

Текущая test-ветка считается рабочей.

---

# MAIN RULE

Перед началом ЛЮБОЙ новой задачи Codex ОБЯЗАН:

1. Полностью прочитать этот файл.
2. Ознакомиться с текущей структурой проекта.
3. Проверить существующие файлы.
4. НЕ делать предположений о структуре проекта.
5. НЕ переписывать проект с нуля.
6. НЕ делать “улучшения ради улучшений”.
7. Работать surgical changes only.

---

# PROJECT TYPE

Это legacy PHP runtime-first проект.

НЕ:
- SPA
- React
- Vue
- Laravel
- Symfony
- Node.js app
- frontend build system

Это:
- PHP + JS + CSS
- runtime-first architecture
- dispatcher operational UI
- shared-hosting compatible system.

---

# MAIN ENTRYPOINTS

## PRODUCTION

/public_html/fregat/feo/map2.php

## TEST / STAGING

/public_html/fregat/feo/maptest.php

---

# ABSOLUTE CRITICAL RULE

## PRODUCTION FILES CANNOT BE MODIFIED

Запрещено:
- удалять map2.php;
- переименовывать map2.php;
- изменять map2.php;
- деплоить поверх map2.php;
- ломать production.

---

# REAL WORKING FTP PATH

Единственный правильный рабочий путь:

/home/s/spugovxsim/public_html/fregat/feo/

---

# WRONG NESTED PATH (FORBIDDEN)

ЗАПРЕЩЕНО работать с:

/home/s/spugovxsim/home/s/spugovxsim/public_html/fregat/feo/

Это ошибочный вложенный путь.

Любые deploy/upload туда считаются ошибкой.

---

# WORKING FILES

Разрешено изменять:

/public_html/fregat/feo/maptest.php
/public_html/fregat/feo/map_files/*

---

# ALLOWED DIRECTORIES

Codex МОЖЕТ работать только внутри:

/public_html/fregat/feo/map_files/

Включая:

/public_html/fregat/feo/map_files/assets/
/public_html/fregat/feo/map_files/Repositories/
/public_html/fregat/feo/map_files/Services/
/public_html/fregat/feo/map_files/Views/

---

# FORBIDDEN FILES

Без отдельного разрешения НЕЛЬЗЯ изменять:

/public_html/fregat/feo/map2.php
/public_html/fregat/feo/menu2.php
/public_html/fregat/feo/config.php
/public_html/fregat/feo/get_planned_routes.php
/public_html/fregat/feo/save_planned_route.php
/public_html/fregat/feo/delete_planned_route.php

---

# JAVASCRIPT FILE RULES

Внешние JS-файлы:
- map.js
- maptest.js

НЕ МОГУТ содержать:
- <?php
- ?>
- <?=
- <script
- </script>
- HTML
- inline PHP

Весь PHP bootstrap передавать только через:

window.MAP_BOOTSTRAP

в:
map_files/Views/scripts.php

---

# MAPTEST.JS RULE

Для test-ветки использовать:

map_files/assets/maptest.js

НЕ:
map.js

scripts.php в maptest.php должен подключать:

map_files/assets/maptest.js?v=<filemtime>

---

# UTF-8 RULES

Все:
- .php
- .js
- .css

обязаны быть:
UTF-8 without BOM

---

# STATIC CACHE RULE

Если меняется:
- JS
- CSS

использовать versioned URL:

?v=filemtime(...)

---

# DEPLOY CHECKLIST

Перед завершением каждой задачи Codex ОБЯЗАН проверить:

1. Правильный FTP path.
2. Нет upload во вложенный path.
3. Нет BOM.
4. Нет PHP inside JS.
5. maptest.js реально загружается по HTTP.
6. Console без SyntaxError.
7. Network без 404/500.
8. Runtime работает.
9. production map2.php НЕ изменялся.

---

# RUNTIME TEST URL

После FTP upload ОБЯЗАТЕЛЬНО открыть:

http://spugovxsim.temp.swtest.ru/fregat/feo/maptest.php

---

# RUNTIME TEST CHECKLIST

## Browser
- карта открывается;
- панели отображаются;
- markers отображаются;
- transport отображается;
- routes работают;
- filters работают.

## Console
- нет красных ошибок;
- нет SyntaxError;
- нет runtime JS errors.

## Network
- нет 404;
- нет 500;
- нет failed fetch.

---

# DEBUGGING RULES

Если есть ошибка:

1. Найти root cause.
2. Исправить минимально.
3. Проверить runtime.
4. НЕ делать workaround вслепую.
5. НЕ ломать рабочую систему.

---

# SAFE UI STRINGS RULE

Все новые UI-строки в JS:
- alerts
- prompts
- confirms
- modal labels
- button labels

должны использовать:
const UI = { ... }

и safe Unicode escapes.

---

# REPORT FORMAT

После каждой задачи ОБЯЗАТЕЛЬНО дать отчёт:

1. Какие файлы изменены.
2. Какие файлы загружены по FTP.
3. В какой FTP path загружены.
4. Что исправлено.
5. Что проверено runtime.
6. Есть ли Console errors.
7. Есть ли Network errors.
8. production map2.php НЕ изменялся.
