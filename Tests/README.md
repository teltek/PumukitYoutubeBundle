# 🧪 Sistema de Pruebas - YoutubeBundle

## 📋 Tipos de Tests

### 1️⃣ **Tests Unitarios** (`Tests/Unit/`)

Verifican la lógica de negocio aislada, sin dependencias externas.

**Características:**
- ✅ Rápidos (milisegundos)
- ✅ No requieren base de datos
- ✅ Usan mocks para dependencias
- ✅ Alta cobertura de código

**Tests implementados:**
- `UploadVideoMessageTest`: Value object del mensaje
- `UploadVideoMessageHandlerTest`: Lógica del handler con mocks
- `BackofficeListenerTest`: Listener con mocks

**Ejecutar:**
```bash
./run_youtube_tests.sh unit
```

---

### 2️⃣ **Tests de Integración** (`Tests/Integration/`)

Verifican que los componentes funcionan correctamente con servicios reales (MongoDB).

**Características:**
- ⚠️ Más lentos (segundos)
- ⚠️ Requieren MongoDB activo
- ✅ Verifican persistencia real
- ✅ Detectan problemas de configuración

**Tests implementados:**
- `YoutubeAccountRepositoryTest`: CRUD de cuentas en MongoDB
- `YoutubePlaylistRepositoryTest`: CRUD de playlists

**Ejecutar:**
```bash
./run_youtube_tests.sh integration
```

**Requisitos:**
- MongoDB corriendo en Docker
- Colecciones de test aisladas

---

### 3️⃣ **Tests Funcionales** (`Tests/Functional/`)

Verifican el comportamiento end-to-end de la aplicación (HTTP requests).

**Características:**
- ⚠️ Más lentos
- ⚠️ Requieren aplicación completa
- ✅ Simulan comportamiento de usuario
- ✅ Verifican routes, controladores, vistas

**Tests implementados:**
- `YoutubePublicationConfigControllerTest`: Endpoints del controlador

**Ejecutar:**
```bash
./run_youtube_tests.sh functional
```

---

## 🚀 Ejecución de Tests

### **Ejecutar todos los tests**
```bash
./run_youtube_tests.sh all
```

### **Ejecutar con cobertura de código**
```bash
./run_youtube_tests.sh coverage
```

Genera un reporte HTML en `var/coverage/index.html`

### **Ejecutar manualmente con PHPUnit**

**Un test específico:**
```bash
docker exec pumukit-php-1 bin/phpunit \
    src/Pumukit/YoutubeBundle/Tests/Unit/UploadVideoMessageTest.php
```

**Con filtro por método:**
```bash
docker exec pumukit-php-1 bin/phpunit \
    --filter testCanCreateUploadVideoMessage \
    src/Pumukit/YoutubeBundle/Tests/Unit/UploadVideoMessageTest.php
```

**Con output verbose:**
```bash
docker exec pumukit-php-1 bin/phpunit \
    --testdox \
    src/Pumukit/YoutubeBundle/Tests/Unit/
```

---

## 📊 Estructura de Tests

```
YoutubeBundle/Tests/
├── Unit/                                    # Tests unitarios
│   ├── UploadVideoMessageTest.php          # Value object
│   ├── UpdateVideoMessageTest.php          # Value object
│   ├── DeleteVideoMessageTest.php          # Value object
│   ├── UploadVideoMessageHandlerTest.php   # Handler con mocks
│   └── BackofficeListenerTest.php          # Listener con mocks
│
├── Integration/                             # Tests de integración
│   ├── YoutubeAccountRepositoryTest.php    # Repositorio + MongoDB
│   └── YoutubePlaylistRepositoryTest.php   # Repositorio + MongoDB
│
└── Functional/                              # Tests funcionales
    ├── YoutubePublicationConfigControllerTest.php  # Controller
    └── UploadVideoWorkflowTest.php         # Workflow completo
```

---

## 🎯 Principios de Testing

### **AAA Pattern (Arrange-Act-Assert)**

```php
public function testExample(): void
{
    // Arrange - Preparar datos
    $message = new UploadVideoMessage('id', 'account', []);
    
    // Act - Ejecutar acción
    $result = $handler->handle($message);
    
    // Assert - Verificar resultado
    $this->assertTrue($result);
}
```

### **Naming Convention**

Los nombres de tests deben ser descriptivos:

✅ **BIEN:**
```php
testDispatchesUploadMessageWhenYoutubeChannelIsMarked()
testLogsErrorWhenMultimediaObjectNotFound()
testReturnsNullWhenAccountNotFound()
```

❌ **MAL:**
```php
testUpload()
testHandler()
testError()
```

### **Data Providers**

Para tests parametrizados:

```php
/**
 * @dataProvider invalidIdProvider
 */
public function testThrowsExceptionForInvalidId(string $invalidId): void
{
    $this->expectException(\InvalidArgumentException::class);
    new UploadVideoMessage($invalidId, 'account', []);
}

public function invalidIdProvider(): array
{
    return [
        'empty string' => [''],
        'too short' => ['123'],
        'invalid format' => ['not-an-objectid'],
    ];
}
```

---

## 🔧 Mocking con PHPUnit

### **Mock de Servicios**

```php
$mock = $this->createMock(YoutubeAccountRepositoryInterface::class);

// Configurar retorno
$mock->method('findOneByLogin')
     ->with('test-account')
     ->willReturn($account);

// Verificar que se llamó
$mock->expects($this->once())
     ->method('save')
     ->with($this->isInstanceOf(YoutubeAccount::class));
```

### **Mock de DocumentManager**

```php
$repository = $this->createMock(DocumentRepository::class);
$repository->method('find')->willReturn($multimediaObject);

$this->documentManager
    ->method('getRepository')
    ->willReturn($repository);
```

---

## 📈 Cobertura de Código

### **Objetivo de Cobertura**

- **Dominio (Domain/)**: 100% (lógica crítica)
- **Application (Handlers)**: ≥90%
- **Infrastructure**: ≥70%
- **Controllers**: ≥80%

### **Ver Cobertura Actual**

```bash
./run_youtube_tests.sh coverage

# Abrir reporte
xdg-open var/coverage/index.html  # Linux
open var/coverage/index.html      # macOS
```

---

## 🐛 Debugging Tests

### **PHPUnit con Xdebug**

```bash
# En docker-compose.yml, agregar a PHP:
XDEBUG_MODE=coverage,debug

# Ejecutar con breakpoints
docker exec pumukit-php-1 bin/phpunit --filter testSpecific
```

### **Logs en Tests**

```php
// Usar var_dump en tests (solo para debugging)
var_dump($message->playlists());

// O fwrite para no romper assertions
fwrite(STDERR, print_r($data, true));
```

### **Tests con --testdox**

Output legible:

```bash
docker exec pumukit-php-1 bin/phpunit --testdox src/Pumukit/YoutubeBundle/Tests/Unit/

# Output:
# Upload Video Message
#  ✔ Can create upload video message
#  ✔ Can create message without playlists
#  ✔ Message is immutable
```

---

## 🔄 Integración Continua (CI)

### **GitHub Actions Ejemplo**

```yaml
name: Tests

on: [push, pull_request]

jobs:
  tests:
    runs-on: ubuntu-latest
    
    services:
      mongodb:
        image: mongo:5.0
        ports:
          - 27017:27017
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mongodb
      
      - name: Install dependencies
        run: composer install
      
      - name: Run tests
        run: |
          bin/phpunit --testsuite "Project Test Suite" \
            --filter "YoutubeBundle" \
            --coverage-clover coverage.xml
      
      - name: Upload coverage
        uses: codecov/codecov-action@v2
```

---

## 📚 Recursos

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Symfony Testing Guide](https://symfony.com/doc/current/testing.html)
- [Test Doubles (Mocks)](https://phpunit.de/manual/current/en/test-doubles.html)
- [Doctrine Testing](https://www.doctrine-project.org/projects/doctrine-mongodb-odm/en/latest/reference/testing.html)

---

## ✅ Checklist para Nuevos Tests

Al agregar una nueva feature:

- [ ] Test unitario para el mensaje (si aplica)
- [ ] Test unitario para el handler con mocks
- [ ] Test de integración para el repositorio (si aplica)
- [ ] Test funcional para el endpoint (si aplica)
- [ ] Verificar cobertura ≥80%
- [ ] Documentar edge cases
- [ ] Verificar que pasan en CI

---

## 🎓 Para tu TFG

### **Métricas a incluir:**

1. **Número de tests**: X unitarios, Y integración, Z funcionales
2. **Cobertura de código**: XX%
3. **Tiempo de ejecución**: Tests completos en X segundos
4. **Casos de prueba**: Positivos, negativos, edge cases

### **Beneficios a destacar:**

- ✅ **Confianza**: Refactoring seguro
- ✅ **Documentación**: Tests como especificación
- ✅ **Regresiones**: Detectar bugs tempranamente
- ✅ **Calidad**: Código más mantenible

