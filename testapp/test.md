php /var/www/neuronapp/bin/testconsole sessions:clear

php /var/www/neuronapp/bin/testconsole simplemessage --agent agent-main --message "Что за файл temp/user_1.md ?"

php /var/www/neuronapp/bin/testconsole simplemessage --agent agent-main --message "О чем файл temp/otrochestvo.txt?"

php /var/www/neuronapp/bin/testconsole simplemessage --agent agent-main --message "Какие у тебя есть инструменты и что ты можешь?"

php /var/www/neuronapp/bin/console simplemessage --agent agent-main --message "Выведи список переменных"

php /var/www/neuronapp/bin/console simplemessage --agent agent-main --message "models/listeners/editorComments/ProcessEventNewAddedListener.php напиши краткое резюме по файлу"

php /var/www/neuronapp/bin/console simplemessage --agent agent-main --message "web/page.demo-ymap/page.companies/feature.company-layer/company-overlay.component.ts напиши краткое резюме по файлу"

php ./bin/console kb:filestruct:dump /var/www/site.loc ~/site.loc.structure.tsv --exclude="vendor/*" --exclude="node_modules/*" --exclude="/.*" --exclude="runtime/*" --exclude=".git/*" --exclude="web/assets/*"  --exclude="nalog/*"

php /var/www/neuronapp/bin/testconsole orchestrate --agent agent-main --init load-book/init  --step load-book/step --finish load-book/finish

php /var/www/neuronapp/bin/testconsole todolist --agent agent-main --todolist load-book/complex

