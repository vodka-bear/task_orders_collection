<?php

declare(strict_types=1);

enum Status: string
{
    case paid = 'paid';
    case pending = 'pending';
}

class Order
{
    public readonly int $id;

    public readonly string $customer;

    public readonly Status $status;

    public readonly int|float $amount;

    public function __construct(array $data)
    {
        $this->validate($data);

        $this->id = $data['id'];
        $this->customer = $data['customer'];
        $this->status = Status::from($data['status']);
        $this->amount = $data['amount'];
    }

    private function validate(array $data): void
    {
        foreach (['id', 'customer', 'status', 'amount'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new Exception("У заказа отсутствует обязательное поле: {$field}");
            }
        }

        if (!is_int($data['id']) || $data['id'] <= 0) {
            throw new Exception('Идентификатор заказа должен быть положительным целым числом');
        }

        if (!is_string($data['customer']) || $data['customer'] === '') {
            throw new Exception('Имя покупателя отсутствует или имеет некорректный тип');
        }

        if (!is_int($data['amount']) && !is_float($data['amount'])) {
            throw new Exception('Сумма заказа имеет некорректный тип');
        }

        if (is_float($data['amount']) && !is_finite($data['amount'])) {
            throw new Exception('Сумма заказа имеет некорректный тип');
        }

        if ($data['amount'] < 0) {
            throw new Exception('Сумма заказа отрицательная');
        }

        if (!is_string($data['status']) || Status::tryFrom($data['status']) === null) {
            throw new Exception('Статус заказа неизвестен');
        }
    }

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'customer' => $this->customer,
            'status'   => $this->status->value,
            'amount'   => $this->amount,
        ];
    }
}

class OrderCollection implements JsonSerializable
{
    /**
     * @var Order[]
     */
    private array $orders;

    public function __construct(array $orders = [])
    {
        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                throw new Exception('Объект order должен быть класса ' . Order::class);
            }
        }

        $this->orders = array_values($orders);
    }

    public static function createFromJson(string $json): self
    {
        try {
            $arOrders = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new Exception('JSON не удалось декодировать', previous: $e);
        }

        if (!is_array($arOrders) || !array_is_list($arOrders)) {
            throw new Exception('JSON должен содержать список заказов');
        }

        $orders = [];

        foreach ($arOrders as $index => $arOrder) {
            if (!is_array($arOrder)) {
                throw new Exception("Заказ с индексом {$index} имеет некорректный формат");
            }

            $orders[] = new Order($arOrder);
        }

        return new self($orders);
    }

    public function add(Order $order): self
    {
        $this->orders[] = $order;

        return $this;
    }

    /**
     * @return Order[]
     */
    public function list(): array
    {
        return $this->orders;
    }

    public function count(): int
    {
        return count($this->orders);
    }

    private function checkColumn(Order $order, string $column): void
    {
        if (!property_exists($order, $column)) {
            throw new Exception("Параметр {$column} отсутствует в " . Order::class);
        }
    }

    public function filterBy(string $column, mixed $value, string $operator = '='): self
    {
        $orders = array_filter($this->orders, function (Order $order) use ($column, $value, $operator) {
            $this->checkColumn($order, $column);

            return match ($operator) {
                '='  => $order->$column === $value,
                '!=' => $order->$column !== $value,
                '>'  => $order->$column > $value,
                '>=' => $order->$column >= $value,
                '<'  => $order->$column < $value,
                '<=' => $order->$column <= $value,
                default => throw new Exception("Неизвестный оператор: $operator")
            };
        });

        return new self($orders);
    }

    public function sumBy(string $column): int|float
    {
        return array_sum(
            array_map(function (Order $order) use ($column) {
                $this->checkColumn($order, $column);

                if (!is_numeric($order->$column)) {
                    throw new Exception("Параметр {$column} должен быть числом. Текущее значение: " . $order->$column);
                }

                return $order->$column;
            }, $this->orders)
        );
    }

    public function groupBy(string $column): array
    {
        $res = [];

        foreach ($this->orders as $order) {
            $this->checkColumn($order, $column);

            $key = $order->$column;

            if ($key instanceof BackedEnum) {
                $key = $key->value;
            }

            $key = (string)$key;

            if (!isset($res[$key])) {
                $res[$key] = new self();
            }

            $res[$key]->add($order);
        }

        return $res;
    }

    public function filterByStatus(Status $status): self
    {
        return $this->filterBy('status', $status);
    }

    public function filterByMinAmount(int|float $value): self
    {
        return $this->filterBy('amount', $value, '>=');
    }

    public function getTotalByStatus(Status $status): int|float
    {
        return $this->filterByStatus($status)->sumBy('amount');
    }

    public function getCountByStatus(Status $status): int
    {
        return $this->filterByStatus($status)->count();
    }

    public function getMostExpensiveByStatus(Status $status): ?Order
    {
        $orders = $this->filterByStatus($status);

        return array_reduce($orders->list(), function (?Order $carry, Order $order) {
            if (!$carry || $carry->amount < $order->amount) {
                $carry = $order;
            }

            return $carry;
        });
    }

    public function groupByStatus(): array
    {
        return $this->groupBy('status');
    }

    public function toJson(): string
    {
        return json_encode(
            $this->jsonSerialize(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    public function saveAsJson(string $outputFilePath): true
    {
        $file = fopen($outputFilePath, 'w');

        if (!$file) {
            throw new Exception('Не удалось создать файл');
        }

        fwrite($file, $this->toJson());
        fclose($file);

        return true;
    }

    public function jsonSerialize(): mixed
    {
        return array_map(fn (Order $order) => $order->toArray(), array_values($this->orders));
    }
}

$jsonFilePath = __DIR__ . '/orders.json';

try {
    if (!file_exists($jsonFilePath) || !is_readable($jsonFilePath)) {
        throw new Exception('JSON файл не найден или недоступен для чтения');
    }

    $json = file_get_contents($jsonFilePath);

    $orderCollection = OrderCollection::createFromJson($json);

    /** Получение списка заказов из JSON */
    $ordersAll = $orderCollection->list();
    echo 'Список всех заказов:' . PHP_EOL . $orderCollection->toJson() . PHP_EOL;

    /** Выбор заказов со статусом paid */
    $ordersPaid = $orderCollection->filterByStatus(Status::paid);
    echo 'Список заказов со статусом paid:' . PHP_EOL . $ordersPaid->toJson() . PHP_EOL;

    /** Подсчёт количества оплаченных заказов */
    $ordersPaidCount = $orderCollection->getCountByStatus(Status::paid);
    echo 'Количество оплаченных заказов: ' . $ordersPaidCount . PHP_EOL;

    /** Подсчёт общей суммы оплаченных заказов */
    $orderTotal = $orderCollection->getTotalByStatus(Status::paid);
    echo 'Общая сумма оплаченных заказов: ' . $orderTotal . PHP_EOL;

    /** Определение самого дорогого оплаченного заказа */
    $ordersMostExpensive = $orderCollection->getMostExpensiveByStatus(Status::paid);
    if ($ordersMostExpensive) {
        echo "Самый дорогой оплаченный заказ: № {$ordersMostExpensive->id} на сумму {$ordersMostExpensive->amount}" . PHP_EOL;
    } else {
        echo 'Оплаченных заказов нет' . PHP_EOL;
    }

    /** Фильтрация заказов по минимальной сумме */
    $ordersFilteredByAmount = $orderCollection->filterByMinAmount(2000);
    echo 'Заказы с суммой не менее 2000: ' . PHP_EOL . $ordersFilteredByAmount->toJson() . PHP_EOL;

    /** Группировка заказов по статусам с подсчётом количества заказов в каждой группе */
    $ordersGroupByStatus = $orderCollection->groupByStatus();
    echo 'Заказы с группировкой по статусу:' . PHP_EOL
        . json_encode(array_map(function (OrderCollection $orders) {
            return ['orders' => $orders, 'count' => $orders->count()];
        }, $ordersGroupByStatus), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        . PHP_EOL;

    /** Сохранение результата обработки в отдельный JSON-файл */
    $result = [
        'paid_count' => $ordersPaidCount,
        'paid_total' => $orderTotal,
        'most_expensive_paid' => $ordersMostExpensive?->toArray(),
        'filtered_by_min_amount' => $ordersFilteredByAmount,
        'grouped_by_status' => array_map(
            fn (OrderCollection $orders) => [
                'orders' => $orders,
                'count' => $orders->count(),
            ],
            $ordersGroupByStatus
        ),
    ];

    if (!file_put_contents(
            __DIR__ . '/output.json',
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        )) {
        throw new Exception('Не удалось создать файл');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
