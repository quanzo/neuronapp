<?php

namespace app\modules\neuron\classes;

/**
 * Сервисный класс для выполнения функции
 */
class WaitSuccess
{
    /**
     * Выполняет функцию $execFunc. Перехватывает ошибки и если они есть, то повторяет с задержкой $waitMicrosec максимальное кол-во раз $maxExecCount
     * Если за это время выполнится не удачно, то будет выброшено исключение.
     * Предназначено для выполнения запросов к серверу - когда сервер может то работать, то нет
     * Для отправки запросов к серверу БД - если сервер БД вдруг может gone away
     *
     * @param callable $funcExec Вызываемая без аргументов логика (например, вызов LLM).
     * @param int      $waitMicrosec Задержка между попытками в микросекундах.
     * @param int      $maxExecCount Максимальное число попыток выполнения.
     * @param callable|null $funcAfterError Вызывается после каждой неудачной попытки с сигнатурой
     *        `callable(\Throwable $e, int $execCount): ?\Throwable`. Если вернуть **null** (или ничего не возвращать),
     *        для следующей итерации сохраняется исходное исключение; если вернуть другой `\Throwable`, он подставится вместо исходного.
     *
     * @return void
     */
    public static function waitSuccess(callable $funcExec, $waitMicrosec = 1000, $maxExecCount = 10, ?callable $funcAfterError = null)
    {
        $execCount = 0;
        do {
            $err = false;
            try {
                call_user_func($funcExec);
            } catch (\Throwable $e) {
                $err = $e;
                if (is_callable($funcAfterError)) {
                    $err_continue = call_user_func($funcAfterError, $err, $execCount);
                    if ($err_continue !== null) {
                        $err = $err_continue;
                    }
                }
            }
            $execCount++;
            if ($err && $execCount < $maxExecCount && $waitMicrosec) {
                usleep($waitMicrosec);
            }
            if ($err && $execCount >= $maxExecCount) { // попытки исчерпали
                throw $err;
            }
        } while ($err && $execCount < $maxExecCount);
    }
}
