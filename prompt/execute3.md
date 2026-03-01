## Файл `src/classes/config/ConfigurationAgent.php`

Метод `buildSessionKey` формирует ключ сессии агента. Требуется определить protected свойство класса и назвать `sessionKey` - это свойство будет хранить имя текущей сессии агента.

Для свойства `sessionKey` определить геттер и сеттер. Сеттер должен не только изменять sessionKey но и сбрасывать resetChatHistory

В методы `makeFromArray` и `makeFromFile` добавить возможность указать имя сессии. Если ключ не указан в методах make* то значение свойства класса `sessionKey` мы будем в момент создания экземпляра класса `ConfigurationAgent` - метод buidSessionKey должне быть вызван статичном конструкторе только если ему не передано имя сессии (ключ сессии). 

## ConfigurationApp

В классе `ConfigurationApp` определить свойство `sessionKey`. Определить сеттер и геттер.

Из `ConfigurationAgent` метод `buildSessionKey` перенести в `ConfigurationApp` и сделать его публичным.

Свойство sessionKey из `ConfigurationAgent` надо использовать через `AgentProducer` в `ConfigurationAgent`. __Обязательно учесть__, что имя агента подставляется в имя сессии (или ключ) именно в классе `ConfigurationAgent`!



В AgentProducer определить свойство sessionKey по умолчанию null. Создать геттер и сеттер.

В методе createFromFile класс AgentProducer сессия должна подставляться из свойства sessionKey этого же класса.

В классе ConfigurationApp, метод getAgentProducer - вот именно в этом методе надо устанавливать sessionKey в создаваемом экземпляре класса AgentProducer!


В классе `ConfigurationApp` определим статичный метод `getSessionDirName(): string` он будет возвращать имя директории для хранении сессий. Имя папки для хранения сессий == `.sessions`. Это используется в методе `getChatHistory` класса `ConfigurationAgent`. Создадим В классе `ConfigurationApp` статичный метод `getSessionDir(): string` и он будет возвращать полный путь к папке хранения сессий - скорректировать `getChatHistory`

