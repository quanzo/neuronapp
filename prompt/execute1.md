Класс `AgentProducer` возвращает экземпляры класса `ConfigurationAgent` по имени. Определим статичный метод в `AgentProducer` который будет возвращать имя агента по умолчанию - `getDefaultAgentName(): string` и определим protected static свойство класса `protected static $defaultAgentName = 'default';`

Агенты в приложении являются исполнителями. Именно они должны запускать `TodoList` и `Skill` в работу.

Классы `TodoList` и `Skill` через `APromptComponent` имеют массив `options`. В `APromptComponent` определим метод `getAgentName`, который должен вернуть имя агента-исполнителя. По умолчанию агентом-исполнителем является = default. Если в options есть свойство agent - то вернуть его.

В `APromptComponent` будет метод `executeFromAgent(ConfigurationAgent $agentCfg): PromiseInterface` - он должен запустить асинхронное выполнение содержимого элемента через `NeuronAI`. При этом `Skill` выполняется за один проход. А в `TodoList` идет последовательное исполнение каждого элемента списка и полное исполнение будет завершено по прохождении помледненго элемента списка. 

Методы `executeFromAgent` вторым параметром должны принимать роль и по умолчанию она должна быть `MessageRole::USER`.

Пусть Future которое возвращает метод `executeFromAgent` в классе `TodoList` возвращает копию истории сообщений агента, который исполнил TodoList.