-- ================================================================
-- POS/ERP DATABASE SCHEMA  v3.0
-- ================================================================
-- Engine    : MySQL 8.0+  |  Collation: utf8mb4_0900_ai_ci
--
-- PRICING (application layer):
--   Wholesale line: (unit_price + wholesale_markup) * qty
--   Retail line:    (unit_price / uom.conversion_factor) * qty + markup
--
-- STOCK:
--   Ledger: inventory_transactions (always base units)
--   Cache:  current_stock per branch (shop_quantity + store_quantity)
--   location on each transaction: 'shop' | 'store'
--
-- CHANNELS (sales.channel) — same cart/checkout services, different clients:
--   pos     = POS terminal (cashiers)
--   mobile  = van / route salesperson app
--   backend = admin / warehouse / small-shop sales module (no till)
--
-- DEPLOYMENT (organizations.deployment_profile + enabled_modules JSON):
--   small_shop        = backend sales + inventory; no POS/mobile
--   wholesale_retail  = full stack: POS + mobile + backend + HR
--   distribution      = backend + mobile + warehouse fulfillment; no POS
--
-- CUSTOMERS: debtors + route customers only (no walk-ins)
--   Walk-in name: sales.customer_name_override
--
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ================================================================
-- SECTION 1: PLATFORM
-- ================================================================

DROP TABLE IF EXISTS organizations;
CREATE TABLE organizations (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    company_code    VARCHAR(45)   UNIQUE NOT NULL,
    logo            VARCHAR(200),
    org_name        VARCHAR(200)  NOT NULL,
    org_email       VARCHAR(200)  NOT NULL,
    primary_tel     VARCHAR(45)   NOT NULL,
    secondary_tel   VARCHAR(45),
    addn_tel1       VARCHAR(45),
    addn_tel2       VARCHAR(45),
    org_address     VARCHAR(400)  NOT NULL,
    org_pin         VARCHAR(45),
    vat_regno            VARCHAR(50),
    deployment_profile   ENUM('small_shop','wholesale_retail','distribution')
                         NOT NULL DEFAULT 'wholesale_retail',
    enabled_modules      JSON          NULL,  -- override profile defaults per module key
    module_settings      JSON          NULL,  -- per-module options (auto truck, stages, etc.)
    created_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_deployment_profile (deployment_profile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS branches;
CREATE TABLE branches (
    id             INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id INT          NOT NULL,
    branch_code    VARCHAR(45)   UNIQUE NOT NULL,
    branch_name    VARCHAR(200)  NOT NULL,
    branch_address VARCHAR(400),
    branch_phone   VARCHAR(45),
    branch_email   VARCHAR(200),
    branch_type    ENUM('supermarket','wholesale','retail','distribution','small_shop')
                   NOT NULL DEFAULT 'retail',
    is_active      BOOLEAN       DEFAULT TRUE,
    settings       JSON,         -- stock_alert_mode, global_low_stock_threshold, default_sale_location
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    INDEX idx_branch_type (branch_type),
    INDEX idx_is_active   (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
    id          INT           PRIMARY KEY AUTO_INCREMENT,
    role_name   VARCHAR(250)  UNIQUE NOT NULL,
    scope       ENUM('org','branch') NOT NULL DEFAULT 'branch',
    is_active   BOOLEAN       DEFAULT TRUE,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS permissions;
CREATE TABLE permissions (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    permission_name VARCHAR(100)  UNIQUE NOT NULL,
    permission_code VARCHAR(50)   UNIQUE NOT NULL,
    module          VARCHAR(50)   NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS role_permissions;
CREATE TABLE role_permissions (
    role_id       INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id             INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id INT          NOT NULL,
    branch_id      INT           NULL,
    role_id        INT           NOT NULL,
    username       VARCHAR(50)   UNIQUE NOT NULL,
    email          VARCHAR(255),
    password       VARCHAR(255)  NOT NULL,
    full_name      VARCHAR(200)  NOT NULL,
    is_admin       TINYINT       DEFAULT 0,
    is_mobile_user TINYINT       DEFAULT 0,
    is_active      BOOLEAN       DEFAULT TRUE,
    last_login     TIMESTAMP     NULL,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_by     INT           NULL,
    deleted_at     TIMESTAMP     NULL,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (branch_id)     REFERENCES branches(id),
    FOREIGN KEY (role_id)         REFERENCES roles(id),
    FOREIGN KEY (deleted_by)      REFERENCES users(id),
    INDEX idx_username   (username),
    INDEX idx_is_mobile  (is_mobile_user),
    INDEX idx_is_active  (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS tills;
CREATE TABLE tills (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    branch_id       INT           NOT NULL,
    till_number     VARCHAR(200)  NOT NULL,
    ip_address      VARCHAR(45)   UNIQUE,
    cashier_id      INT           NOT NULL,
    working_amount  INT           NOT NULL DEFAULT 0,
    float_breakdown JSON,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)  REFERENCES branches(id),
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    INDEX idx_branch_id  (branch_id),
    INDEX idx_cashier_id (cashier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS till_float_sessions;
CREATE TABLE till_float_sessions (
    id               BIGINT        PRIMARY KEY AUTO_INCREMENT,
    till_id          INT           NOT NULL,
    branch_id        INT           NOT NULL,
    cashier_id       INT           NOT NULL,
    session_date     DATE          NOT NULL,
    working_amount   INT           NOT NULL DEFAULT 0,
    float_breakdown  JSON,
    closing_amount   DECIMAL(10,2) NULL,
    expected_amount  DECIMAL(10,2) NULL,
    cash_sales       DECIMAL(10,2) DEFAULT 0,
    expenses_total   DECIMAL(10,2) DEFAULT 0,
    opened_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    closed_at        TIMESTAMP     NULL,
    status           ENUM('open','closed','suspended') DEFAULT 'open',
    notes            TEXT,
    FOREIGN KEY (till_id)    REFERENCES tills(id),
    FOREIGN KEY (branch_id)  REFERENCES branches(id),
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    INDEX idx_till_id      (till_id),
    INDEX idx_cashier_id   (cashier_id),
    INDEX idx_session_date (session_date),
    INDEX idx_status       (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 2: CATALOGUE
-- ================================================================

DROP TABLE IF EXISTS suppliers;
CREATE TABLE suppliers (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    supplier_code   VARCHAR(50)   UNIQUE NOT NULL,
    supplier_name   VARCHAR(200)  NOT NULL,
    contact_person  VARCHAR(200),
    email           VARCHAR(100),
    phone           VARCHAR(45),
    alternate_phone VARCHAR(45),
    address         TEXT,
    town            VARCHAR(100),
    tax_pin         VARCHAR(45),
    additional_info TEXT,
    contacts        JSON,         -- [{ "label","phone","email" }, ...]
    organization_id INT           NULL,
    is_active       BOOLEAN       DEFAULT TRUE,
    created_by      INT           NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_by      INT           NULL,
    deleted_at      TIMESTAMP     NULL,
    FOREIGN KEY (created_by)      REFERENCES users(id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS vats;
CREATE TABLE vats (
    id             INT           PRIMARY KEY AUTO_INCREMENT,
    vat_code       VARCHAR(20)   UNIQUE NOT NULL,
    vat_name       VARCHAR(100)  NOT NULL,
    vat_percentage DECIMAL(5,2)  NOT NULL,
    is_active      BOOLEAN       DEFAULT TRUE,
    created_by     INT           NULL,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS uoms;
CREATE TABLE uoms (
    id                INT           PRIMARY KEY AUTO_INCREMENT,
    conversion_factor FLOAT         NOT NULL DEFAULT 1,
    full_name         VARCHAR(200)  NOT NULL,
    uom_type          VARCHAR(45)   NOT NULL,
    is_base_unit      BOOLEAN       DEFAULT FALSE,
    is_active         BOOLEAN       DEFAULT TRUE,
    created_by        INT           NULL,
    deleted_by        INT           NULL,
    deleted_at        DATETIME      NULL,
    created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (deleted_by) REFERENCES users(id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
    id            INT           PRIMARY KEY AUTO_INCREMENT,
    category_name VARCHAR(200)  NOT NULL,
    created_by    INT           NULL,
    created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS sub_categories;
CREATE TABLE sub_categories (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    category_id      INT           NOT NULL,
    subcategory_name VARCHAR(200)  NOT NULL,
    created_by       INT           NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (created_by)  REFERENCES users(id),
    INDEX idx_category_id (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS products;
CREATE TABLE products (
    id                      INT           PRIMARY KEY AUTO_INCREMENT,
    product_code            VARCHAR(200)  UNIQUE NOT NULL,
    product_name            VARCHAR(200)  NOT NULL,
    subcategory_id          INT           NOT NULL,
    unit_id                 INT           NOT NULL,
    unit_price              FLOAT         NOT NULL,
    last_selling_price      FLOAT         DEFAULT 0,
    last_cost_price         FLOAT,
    discount_type           ENUM('fixed','percentage') DEFAULT 'percentage',
    discount_percentage     FLOAT         NOT NULL DEFAULT 0,
    discount_value          FLOAT         DEFAULT 0,
    product_weight          DOUBLE,
    stock_in_shop           FLOAT         NOT NULL DEFAULT 0,
    stock_in_store          FLOAT         DEFAULT 0,
    supplier_id             INT,
    sell_on_retail          TINYINT       DEFAULT 0,
    vat_id                  INT           NOT NULL,
    organization_id         INT           NOT NULL,
    reorder_point           DECIMAL(10,2) DEFAULT 0,
    created_by              INT           NULL,
    created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME      NULL,
    deleted_by              INT           NULL,
    FOREIGN KEY (subcategory_id)  REFERENCES sub_categories(id),
    FOREIGN KEY (unit_id)         REFERENCES uoms(id),
    FOREIGN KEY (vat_id)          REFERENCES vats(id),
    FOREIGN KEY (supplier_id)     REFERENCES suppliers(id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (created_by)      REFERENCES users(id),
    INDEX idx_product_code (product_code),
    INDEX idx_subcategory_id (subcategory_id),
    INDEX idx_deleted_at   (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS retail_package_settings;
CREATE TABLE retail_package_settings (
    id                     INT           PRIMARY KEY AUTO_INCREMENT,
    product_code           VARCHAR(200)  NOT NULL,
    max_qty_measure        FLOAT,
    markup_price           FLOAT         DEFAULT 0,
    min_uom_measure        VARCHAR(45),
    wholesale_qty_measure  FLOAT         DEFAULT 0,
    wholesale_markup_price FLOAT         DEFAULT 0,
    max_uom_measure        VARCHAR(45),
    created_at             TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_code) REFERENCES products(product_code) ON DELETE CASCADE,
    UNIQUE KEY uq_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS price_history;
CREATE TABLE price_history (
    id              BIGINT        PRIMARY KEY AUTO_INCREMENT,
    product_code    VARCHAR(200)  NOT NULL,
    unit_price      DECIMAL(10,2) NOT NULL,
    cost_price      DECIMAL(10,2) NOT NULL,
    discount_pct    DECIMAL(10,2) DEFAULT 0,
    changed_by      INT           NOT NULL,
    organization_id INT           NOT NULL,
    changed_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_code)    REFERENCES products(product_code),
    FOREIGN KEY (changed_by)      REFERENCES users(id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    INDEX idx_product_code (product_code),
    INDEX idx_changed_at   (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 3: ROUTES & CUSTOMERS
-- ================================================================

DROP TABLE IF EXISTS routes;
CREATE TABLE routes (
    id                 INT           PRIMARY KEY AUTO_INCREMENT,
    route_name         VARCHAR(255)  UNIQUE NOT NULL,
    route_markup_price INT           DEFAULT 0,
    direction          VARCHAR(45),
    is_active          BOOLEAN       DEFAULT TRUE,
    created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS customers;
CREATE TABLE customers (
    customer_num      INT           PRIMARY KEY,
    branch_id         INT           NOT NULL,
    organization_id   INT           NOT NULL,
    customer_name     VARCHAR(200)  NOT NULL,
    customer_type     ENUM('debtor','route') NOT NULL DEFAULT 'debtor',
    phone_number      VARCHAR(45),
    additional_phone  VARCHAR(45),
    town              VARCHAR(200),
    latitude          DECIMAL(10,7) NULL,
    longitude         DECIMAL(10,7) NULL,
    shop_image        VARCHAR(255)  NULL,
    route_id          INT           NULL,
    created_by        INT           NOT NULL,
    customer_status   INT           DEFAULT 0,
    kra_pin           VARCHAR(45),
    terms_of_payment  VARCHAR(45),
    credit_limit      DECIMAL(10,2) DEFAULT 0,
    current_balance   DECIMAL(10,2) DEFAULT 0,
    deleted_by        INT           NULL,
    deleted_at        DATE          NULL,
    created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)       REFERENCES branches(id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (route_id)        REFERENCES routes(id),
    FOREIGN KEY (created_by)      REFERENCES users(id),
    FOREIGN KEY (deleted_by)      REFERENCES users(id),
    INDEX idx_phone         (phone_number),
    INDEX idx_branch_id     (branch_id),
    INDEX idx_route_id      (route_id),
    INDEX idx_customer_type (customer_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 4: INVENTORY
-- ================================================================

DROP TABLE IF EXISTS current_stock;
CREATE TABLE current_stock (
    product_code    VARCHAR(200)  NOT NULL,
    branch_id       INT           NOT NULL,
    shop_quantity   FLOAT         NOT NULL DEFAULT 0,
    store_quantity  FLOAT         NOT NULL DEFAULT 0,
    last_updated    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_code, branch_id),
    FOREIGN KEY (product_code) REFERENCES products(product_code),
    FOREIGN KEY (branch_id)    REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS inventory_transactions;
CREATE TABLE inventory_transactions (
    id               BIGINT        PRIMARY KEY AUTO_INCREMENT,
    branch_id        INT           NOT NULL,
    product_code     VARCHAR(200)  NOT NULL,
    stock_location   ENUM('shop','store') NOT NULL DEFAULT 'shop',
    transaction_type ENUM(
        'PURCHASE','POS_SALE','MOBILE_SALE','BACKEND_SALE','RETURN',
        'DAMAGE','ADJUSTMENT','STOCK_TAKE','TRANSFER','WRITE_OFF','SUPPLIER_RETURN'
    ) NOT NULL,
    reference_type   VARCHAR(50),
    reference_id     BIGINT,
    quantity_change  FLOAT         NOT NULL,
    quantity_before  FLOAT         NOT NULL,
    quantity_after   FLOAT         NOT NULL,
    unit_cost        DECIMAL(10,2),
    notes            TEXT,
    created_by       INT           NOT NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)     REFERENCES branches(id),
    FOREIGN KEY (product_code)  REFERENCES products(product_code),
    FOREIGN KEY (created_by)    REFERENCES users(id),
    INDEX idx_branch_product   (branch_id, product_code),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_reference        (reference_type, reference_id),
    INDEX idx_created_at       (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_sync_stock$$
CREATE TRIGGER trg_sync_stock
AFTER INSERT ON inventory_transactions
FOR EACH ROW
BEGIN
    INSERT INTO current_stock (product_code, branch_id, shop_quantity, store_quantity)
    VALUES (
        NEW.product_code,
        NEW.branch_id,
        IF(NEW.stock_location = 'shop', NEW.quantity_after, 0),
        IF(NEW.stock_location = 'store', NEW.quantity_after, 0)
    )
    ON DUPLICATE KEY UPDATE
        shop_quantity  = IF(NEW.stock_location = 'shop', NEW.quantity_after, shop_quantity),
        store_quantity = IF(NEW.stock_location = 'store', NEW.quantity_after, store_quantity),
        last_updated   = CURRENT_TIMESTAMP;
END$$
DELIMITER ;

DROP TABLE IF EXISTS stock_movement_history;
CREATE TABLE stock_movement_history (
    id              BIGINT        PRIMARY KEY AUTO_INCREMENT,
    product_code    VARCHAR(200)  NOT NULL,
    branch_id       INT           NOT NULL,
    quantity_moved  DOUBLE        NOT NULL,
    from_location   ENUM('shop','store') NOT NULL,
    to_location     ENUM('shop','store') NOT NULL,
    moved_by        INT           NOT NULL,
    move_status     INT           DEFAULT 0,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_code) REFERENCES products(product_code),
    FOREIGN KEY (branch_id)    REFERENCES branches(id),
    FOREIGN KEY (moved_by)     REFERENCES users(id),
    INDEX idx_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS damages;
CREATE TABLE damages (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    product_code    VARCHAR(200)  NOT NULL,
    branch_id       INT           NOT NULL,
    quantity        DOUBLE        NOT NULL,
    package_type    ENUM('full_package','partial','pieces') NOT NULL DEFAULT 'partial',
    uom_label       VARCHAR(45),
    stock_location  ENUM('shop','store') NOT NULL DEFAULT 'shop',
    reason          TEXT,
    reported_by     INT           NOT NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_code) REFERENCES products(product_code),
    FOREIGN KEY (branch_id)    REFERENCES branches(id),
    FOREIGN KEY (reported_by)  REFERENCES users(id),
    INDEX idx_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS stock_receipts;
CREATE TABLE stock_receipts (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    product_code    VARCHAR(200)  NOT NULL,
    branch_id       INT           NOT NULL,
    organization_id INT           NOT NULL,
    units_received  FLOAT         NOT NULL,
    stock_location  ENUM('shop','store') NOT NULL DEFAULT 'store',
    invoice_number  VARCHAR(45),
    cost_price      FLOAT,
    received_by     INT           NOT NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_code)    REFERENCES products(product_code),
    FOREIGN KEY (branch_id)       REFERENCES branches(id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (received_by)     REFERENCES users(id),
    INDEX idx_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS supplier_returns;
CREATE TABLE supplier_returns (
    id              BIGINT        PRIMARY KEY AUTO_INCREMENT,
    supplier_id     INT           NOT NULL,
    branch_id       INT           NOT NULL,
    product_code    VARCHAR(200)  NOT NULL,
    quantity        FLOAT         NOT NULL,
    package_type    ENUM('full_package','partial','pieces') NOT NULL DEFAULT 'partial',
    uom_label       VARCHAR(45),
    stock_location  ENUM('shop','store') NOT NULL DEFAULT 'store',
    reason          TEXT,
    reference_type  VARCHAR(50),
    reference_id    BIGINT,
    returned_by     INT           NOT NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id)  REFERENCES suppliers(id),
    FOREIGN KEY (branch_id)    REFERENCES branches(id),
    FOREIGN KEY (product_code) REFERENCES products(product_code),
    FOREIGN KEY (returned_by)  REFERENCES users(id),
    INDEX idx_supplier_id  (supplier_id),
    INDEX idx_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 5: PAYMENTS
-- ================================================================

DROP TABLE IF EXISTS payment_methods;
CREATE TABLE payment_methods (
    id                 INT           PRIMARY KEY AUTO_INCREMENT,
    method_name        VARCHAR(200)  UNIQUE NOT NULL,
    method_code        VARCHAR(20)   UNIQUE NOT NULL,
    requires_reference BOOLEAN       DEFAULT FALSE,
    is_active          BOOLEAN       DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 6: SALES
-- ================================================================

DROP TABLE IF EXISTS sales;
CREATE TABLE sales (
    id                     BIGINT        PRIMARY KEY AUTO_INCREMENT,
    order_num              INT           UNIQUE NOT NULL,
    branch_id              INT           NOT NULL,
    organization_id        INT           NOT NULL,
    channel                ENUM('pos','mobile','backend') NOT NULL DEFAULT 'pos',
    payment_status         ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    amount_paid            DECIMAL(12,2) NOT NULL DEFAULT 0,
    fulfillment_meta       JSON          NULL,  -- vehicle, driver, weights, loading sheet refs
    till_id                INT           NULL,
    float_session_id       BIGINT        NULL,
    cashier_id             INT           NOT NULL,
    customer_num           INT           NULL,
    customer_name_override TEXT,
    route_id               INT           NULL,
    required_date          DATE          NULL,
    delivery_date          DATETIME      NULL,
    status                 ENUM(
        'draft','held','booked','pending','processed',
        'pending_payment','paid','completed','cancelled'
    ) NOT NULL DEFAULT 'draft',
    total_vat              FLOAT         NOT NULL DEFAULT 0,
    order_total            FLOAT         NOT NULL DEFAULT 0,
    cash                   INT           NOT NULL DEFAULT 0,
    mpesa_amount           INT           DEFAULT 0,
    equity_amount          INT           DEFAULT 0,
    kcb_amount             INT           DEFAULT 0,
    order_change           INT           NOT NULL DEFAULT 0,
    payment_method_code    VARCHAR(45)   DEFAULT 'CASH',
    is_credit_sale         TINYINT       DEFAULT 0,
    stock_balanced         INT           DEFAULT 0,
    receipt_printed        INT           DEFAULT 0,
    comments               TEXT,
    archived               TINYINT       DEFAULT 0,
    deleted_by             INT           NULL,
    deleted_at             TIMESTAMP     NULL,
    created_at             TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    completed_at           TIMESTAMP     NULL,
    cancelled_at           TIMESTAMP     NULL,
    cancelled_by           INT           NULL,
    FOREIGN KEY (branch_id)        REFERENCES branches(id),
    FOREIGN KEY (organization_id)  REFERENCES organizations(id),
    FOREIGN KEY (till_id)          REFERENCES tills(id),
    FOREIGN KEY (float_session_id) REFERENCES till_float_sessions(id),
    FOREIGN KEY (cashier_id)       REFERENCES users(id),
    FOREIGN KEY (customer_num)     REFERENCES customers(customer_num),
    FOREIGN KEY (route_id)         REFERENCES routes(id),
    FOREIGN KEY (cancelled_by)     REFERENCES users(id),
    FOREIGN KEY (deleted_by)       REFERENCES users(id),
    INDEX idx_order_num     (order_num),
    INDEX idx_branch_id     (branch_id),
    INDEX idx_channel       (channel),
    INDEX idx_status        (status),
    INDEX idx_cashier_id    (cashier_id),
    INDEX idx_customer_num  (customer_num),
    INDEX idx_archived      (archived),
    INDEX idx_created_at    (created_at),
    INDEX idx_completed_at   (completed_at),
    INDEX idx_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS sale_items;
CREATE TABLE sale_items (
    id                  BIGINT        PRIMARY KEY AUTO_INCREMENT,
    sale_id             BIGINT        NOT NULL,
    product_code        VARCHAR(200)  NOT NULL,
    line_no             INT           NOT NULL DEFAULT 1,
    item_code           VARCHAR(50)   NULL,
    quantity            FLOAT         NOT NULL,
    uom                 VARCHAR(45),
    selling_price       FLOAT         NOT NULL,
    discount_given      FLOAT         NOT NULL DEFAULT 0,
    product_vat         FLOAT         NOT NULL DEFAULT 0,
    amount              FLOAT         NOT NULL,
    on_wholesale_retail INT           DEFAULT 0,
    created_at          DATE          DEFAULT (CURRENT_DATE),
    FOREIGN KEY (sale_id)       REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_code)  REFERENCES products(product_code),
    UNIQUE KEY uq_sale_line (sale_id, line_no),
    INDEX idx_sale_id      (sale_id),
    INDEX idx_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS sale_payments;
CREATE TABLE sale_payments (
    id                BIGINT        PRIMARY KEY AUTO_INCREMENT,
    sale_id           BIGINT        NOT NULL,
    payment_method_id INT           NOT NULL,
    amount            DECIMAL(10,2) NOT NULL,
    reference_number  VARCHAR(100),
    paid_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id)           REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    INDEX idx_sale_id (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS cart_lines;
DROP TABLE IF EXISTS temporary_carts;
CREATE TABLE temporary_carts (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    user_id         INT           NOT NULL,
    branch_id       INT           NULL,
    channel         ENUM('pos','mobile','backend') NOT NULL DEFAULT 'pos',
    till_id         INT           NULL,
    route_id        INT           NULL,
    update_no       INT           DEFAULT 0,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (till_id)   REFERENCES tills(id),
    FOREIGN KEY (route_id)  REFERENCES routes(id),
    INDEX idx_user_channel (user_id, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cart_lines (
    id                  INT           PRIMARY KEY AUTO_INCREMENT,
    cart_id             INT           NOT NULL,
    product_code        VARCHAR(200)  NOT NULL,
    product_name        VARCHAR(250),
    unit_price          DOUBLE,
    quantity            FLOAT,
    uom                 VARCHAR(45),
    product_vat         DOUBLE,
    amount              DOUBLE,
    on_wholesale_retail INT           DEFAULT 0,
    line_no             INT           DEFAULT 1,
    FOREIGN KEY (cart_id) REFERENCES temporary_carts(id) ON DELETE CASCADE,
    INDEX idx_cart_id (cart_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS stock_reservations;
CREATE TABLE stock_reservations (
    id              BIGINT        PRIMARY KEY AUTO_INCREMENT,
    branch_id       INT           NOT NULL,
    product_code    VARCHAR(200)  NOT NULL,
    stock_location  ENUM('shop','store') NOT NULL DEFAULT 'shop',
    quantity        FLOAT         NOT NULL,
    cart_id         INT           NULL,
    sale_id         BIGINT        NULL,
    reserved_by     INT           NOT NULL,
    expires_at      TIMESTAMP     NULL,
    released_at     TIMESTAMP     NULL,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)     REFERENCES branches(id),
    FOREIGN KEY (product_code)  REFERENCES products(product_code),
    FOREIGN KEY (cart_id)       REFERENCES temporary_carts(id) ON DELETE SET NULL,
    FOREIGN KEY (sale_id)       REFERENCES sales(id) ON DELETE SET NULL,
    FOREIGN KEY (reserved_by)   REFERENCES users(id),
    INDEX idx_branch_product (branch_id, product_code),
    INDEX idx_cart_id (cart_id),
    INDEX idx_sale_id (sale_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 7: ACCOUNTS RECEIVABLE
-- ================================================================

DROP TABLE IF EXISTS customer_invoice_payments;
DROP TABLE IF EXISTS customer_invoices;
CREATE TABLE customer_invoices (
    id               BIGINT        PRIMARY KEY AUTO_INCREMENT,
    invoice_number   VARCHAR(50)   UNIQUE NOT NULL,
    sale_id          BIGINT        NOT NULL,
    customer_num     INT           NOT NULL,
    branch_id        INT           NOT NULL,
    organization_id  INT           NOT NULL,
    created_by       INT           NOT NULL,
    invoice_date     DATE          NOT NULL,
    due_date         DATE          NULL,
    total_vat        FLOAT         NOT NULL DEFAULT 0,
    invoice_total    DECIMAL(10,2) NOT NULL,
    amount_paid      DECIMAL(10,2) NOT NULL DEFAULT 0,
    balance_due      DECIMAL(10,2) GENERATED ALWAYS AS (invoice_total - amount_paid) STORED,
    payment_status   TINYINT       NOT NULL DEFAULT 0,
    notes            TEXT,
    deleted_by       INT           NULL,
    deleted_at       DATE          NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id)         REFERENCES sales(id),
    FOREIGN KEY (customer_num)    REFERENCES customers(customer_num),
    FOREIGN KEY (branch_id)       REFERENCES branches(id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (created_by)      REFERENCES users(id),
    FOREIGN KEY (deleted_by)      REFERENCES users(id),
    INDEX idx_customer_num   (customer_num),
    INDEX idx_payment_status (payment_status),
    INDEX idx_invoice_date   (invoice_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_invoice_payments (
    id                  INT           PRIMARY KEY AUTO_INCREMENT,
    customer_invoice_id BIGINT        NOT NULL,
    customer_num        INT           NOT NULL,
    payment_method_id   INT           NOT NULL,
    amount_paid         DECIMAL(10,2) NOT NULL,
    amount_due_snapshot DECIMAL(10,2) NULL,
    cheque_number       VARCHAR(45),
    reference_number    VARCHAR(100),
    date_paid           DATE          NOT NULL,
    received_by         INT           NOT NULL,
    organization_id     INT           NOT NULL,
    notes               TEXT,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_invoice_id) REFERENCES customer_invoices(id),
    FOREIGN KEY (customer_num)        REFERENCES customers(customer_num),
    FOREIGN KEY (payment_method_id)  REFERENCES payment_methods(id),
    FOREIGN KEY (received_by)         REFERENCES users(id),
    FOREIGN KEY (organization_id)   REFERENCES organizations(id),
    INDEX idx_customer_invoice_id (customer_invoice_id),
    INDEX idx_date_paid           (date_paid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$
DROP TRIGGER IF EXISTS trg_update_ar_on_payment$$
CREATE TRIGGER trg_update_ar_on_payment
AFTER INSERT ON customer_invoice_payments
FOR EACH ROW
BEGIN
    DECLARE new_paid DECIMAL(10,2);
    DECLARE inv_total DECIMAL(10,2);

    SELECT (amount_paid + NEW.amount_paid), invoice_total
    INTO new_paid, inv_total
    FROM customer_invoices WHERE id = NEW.customer_invoice_id;

    UPDATE customer_invoices
    SET amount_paid    = new_paid,
        payment_status = CASE
            WHEN new_paid >= inv_total THEN 2
            WHEN new_paid > 0         THEN 1
            ELSE 0
        END,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = NEW.customer_invoice_id;

    UPDATE customers
    SET current_balance = (
        SELECT COALESCE(SUM(balance_due), 0)
        FROM customer_invoices
        WHERE customer_num = NEW.customer_num
          AND payment_status IN (0,1)
          AND deleted_at IS NULL
    )
    WHERE customer_num = NEW.customer_num;
END$$
DELIMITER ;

-- ================================================================
-- SECTION 8: LPO / PURCHASING
-- ================================================================

DROP TABLE IF EXISTS lpo_statuses;
CREATE TABLE lpo_statuses (
    status_code INT           NOT NULL,
    status_name VARCHAR(45),
    PRIMARY KEY (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS lpo_mst;
CREATE TABLE lpo_mst (
    lpo_no              INT           PRIMARY KEY AUTO_INCREMENT,
    supplier_id         INT           NOT NULL,
    reference_number    VARCHAR(45),
    total_amount        DECIMAL(10,2),
    vat_amount          FLOAT,
    net_amount          FLOAT,
    created_by          INT,
    created_at          TIMESTAMP     NULL,
    deleted_by          VARCHAR(45),
    deleted_at          TIMESTAMP     NULL,
    due_date            DATE,
    lpo_status_code     INT           DEFAULT 1,
    delivery_address    VARCHAR(45),
    cleared_flag        INT           DEFAULT 0,
    cleared_by          VARCHAR(45),
    cleared_at          DATETIME,
    email_sent_flag     INT           DEFAULT 0,
    sent_at             DATETIME,
    sent_by             VARCHAR(45),
    supplier_invoice_no VARCHAR(45),
    terms               VARCHAR(200),
    instructions        VARCHAR(200),
    FOREIGN KEY (supplier_id)     REFERENCES suppliers(id),
    FOREIGN KEY (lpo_status_code) REFERENCES lpo_statuses(status_code),
    FOREIGN KEY (created_by)      REFERENCES users(id),
    INDEX idx_supplier_id (supplier_id),
    INDEX idx_lpo_status  (lpo_status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS lpo_txn;
CREATE TABLE lpo_txn (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    lpo_no          INT           NOT NULL,
    product_code    VARCHAR(200)  NOT NULL,
    ordered_qty     DOUBLE        NOT NULL,
    uom             VARCHAR(45),
    cost_price      DOUBLE        NOT NULL,
    received_qty    DOUBLE,
    markup_amount   FLOAT,
    markup_percent  FLOAT,
    FOREIGN KEY (lpo_no)       REFERENCES lpo_mst(lpo_no),
    FOREIGN KEY (product_code) REFERENCES products(product_code),
    INDEX idx_lpo_no       (lpo_no),
    INDEX idx_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS lpo_attachments;
CREATE TABLE lpo_attachments (
    id        INT           PRIMARY KEY AUTO_INCREMENT,
    lpo_no    INT           NOT NULL,
    file_name VARCHAR(200),
    FOREIGN KEY (lpo_no) REFERENCES lpo_mst(lpo_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS lpo_supplier_invoices;
CREATE TABLE lpo_supplier_invoices (
    id                      INT           PRIMARY KEY AUTO_INCREMENT,
    lpo_no                  INT           NOT NULL,
    supplier_id             INT           NOT NULL,
    supplier_invoice_number VARCHAR(100)  NOT NULL,
    invoice_date            DATE          NULL,
    invoice_amount          DECIMAL(12,2) NULL,
    file_path               VARCHAR(500)  NULL,
    file_name               VARCHAR(255)  NULL,
    mime_type               VARCHAR(100)  NULL,
    file_size               INT UNSIGNED  NULL,
    created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lpo_no)     REFERENCES lpo_mst(lpo_no),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    INDEX idx_lpo_no (lpo_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 9: RETURNS
-- ================================================================

DROP TABLE IF EXISTS returns;
CREATE TABLE returns (
    id            INT           PRIMARY KEY AUTO_INCREMENT,
    sale_id       BIGINT        NULL,
    branch_id     INT           NOT NULL,
    product_code  VARCHAR(200)  NOT NULL,
    quantity      FLOAT         NOT NULL,
    uom           VARCHAR(45),
    amount        FLOAT         NOT NULL,
    reason        VARCHAR(200),
    return_type   ENUM('CURRENT','PREVIOUS','MOBILE','SUPPLIER') NOT NULL DEFAULT 'CURRENT',
    item_code     VARCHAR(50)   NULL,
    returned_by   INT           NOT NULL,
    is_mobile     TINYINT       DEFAULT 0,
    created_at    DATE          DEFAULT (CURRENT_DATE),
    FOREIGN KEY (sale_id)      REFERENCES sales(id),
    FOREIGN KEY (branch_id)    REFERENCES branches(id),
    FOREIGN KEY (product_code) REFERENCES products(product_code),
    FOREIGN KEY (returned_by)  REFERENCES users(id),
    INDEX idx_sale_id      (sale_id),
    INDEX idx_product_code (product_code),
    INDEX idx_created_at   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 10: EXPENSES
-- ================================================================

DROP TABLE IF EXISTS expense_groups;
CREATE TABLE expense_groups (
    id         INT           PRIMARY KEY AUTO_INCREMENT,
    group_name VARCHAR(200)  UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS expenses;
CREATE TABLE expenses (
    id                 INT           PRIMARY KEY AUTO_INCREMENT,
    branch_id          INT           NOT NULL,
    expense_group_id   INT           NOT NULL,
    float_session_id   BIGINT        NULL,
    description        VARCHAR(200),
    expense_amount     DECIMAL(10,0),
    expense_date       DATE          NOT NULL,
    balance_due        VARCHAR(45),
    invoice_no         VARCHAR(45),
    receipt_image      MEDIUMBLOB,
    billable_status    INT           DEFAULT 1,
    payment_method_id  INT           NOT NULL,
    recorded_by        INT           NOT NULL,
    deleted_at         DATETIME,
    deleted_by         INT,
    created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP     NULL,
    FOREIGN KEY (branch_id)        REFERENCES branches(id),
    FOREIGN KEY (expense_group_id) REFERENCES expense_groups(id),
    FOREIGN KEY (float_session_id) REFERENCES till_float_sessions(id),
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    FOREIGN KEY (recorded_by)      REFERENCES users(id),
    FOREIGN KEY (deleted_by)       REFERENCES users(id),
    INDEX idx_branch_id    (branch_id),
    INDEX idx_expense_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 11: KRA & AUDIT
-- ================================================================

DROP TABLE IF EXISTS kra_responses;
CREATE TABLE kra_responses (
    id                BIGINT        PRIMARY KEY AUTO_INCREMENT,
    sale_id           BIGINT        NOT NULL,
    order_no          INT           NOT NULL,
    invoice_number    VARCHAR(255)  UNIQUE,
    receipt_signature TEXT,
    signature_link    TEXT,
    serial_number     VARCHAR(255),
    kra_timestamp     VARCHAR(255),
    request_payload   JSON,
    response_payload  JSON,
    status            ENUM('pending','success','failed') DEFAULT 'pending',
    error_message     TEXT,
    created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    INDEX idx_order_no (order_no),
    INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
    id         BIGINT        PRIMARY KEY AUTO_INCREMENT,
    user_id    INT           NOT NULL,
    branch_id  INT           NULL,
    action     VARCHAR(50)   NOT NULL,
    table_name VARCHAR(100)  NOT NULL,
    record_id  VARCHAR(100)  NOT NULL,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)   REFERENCES users(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    INDEX idx_user_id    (user_id),
    INDEX idx_table_name (table_name),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS system_settings;
CREATE TABLE system_settings (
    id                        INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id           INT           NULL,
    allow_below_stock         INT           DEFAULT 0,
    global_low_stock_threshold DECIMAL(10,2) NULL,
    stock_alert_mode          ENUM('per_product','global','both') DEFAULT 'per_product',
    mail_host                 VARCHAR(200),
    mail_user                 VARCHAR(200),
    mail_password             VARCHAR(200),
    mail_port                 INT,
    mail_from                 VARCHAR(200),
    admin_email               VARCHAR(200),
    backup_folder_path        VARCHAR(200),
    customer_debtor_message   TEXT,
    mpesa_callback_url        VARCHAR(350),
    equity_callback_url       VARCHAR(350),
    kra_device_callback_url   VARCHAR(250),
    created_at                TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 12: FULFILLMENT, STOCK TAKE, ACCOUNTING, HR
-- ================================================================

DROP TABLE IF EXISTS vehicles;
CREATE TABLE vehicles (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    branch_id       INT           NOT NULL,
    vehicle_code    VARCHAR(45)   NOT NULL,
    vehicle_name    VARCHAR(200)  NOT NULL,
    plate_number    VARCHAR(45),
    is_active       BOOLEAN       DEFAULT TRUE,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    UNIQUE KEY uq_branch_vehicle (branch_id, vehicle_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS drivers;
CREATE TABLE drivers (
    id                  INT           PRIMARY KEY AUTO_INCREMENT,
    branch_id           INT           NOT NULL,
    user_id             INT           NULL,
    default_vehicle_id  INT           NULL,
    default_route_id    INT           NULL,
    driver_code         VARCHAR(45)   NOT NULL,
    full_name           VARCHAR(200)  NOT NULL,
    phone               VARCHAR(45),
    is_active           BOOLEAN       DEFAULT TRUE,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (default_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (default_route_id) REFERENCES routes(id) ON DELETE SET NULL,
    UNIQUE KEY uq_branch_driver (branch_id, driver_code),
    INDEX idx_default_vehicle (default_vehicle_id),
    INDEX idx_default_route (default_route_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS stock_take_lines;
DROP TABLE IF EXISTS stock_take_sessions;
CREATE TABLE stock_take_sessions (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    branch_id       INT           NOT NULL,
    session_code    VARCHAR(50)   NOT NULL,
    status          ENUM('draft','in_progress','completed','cancelled') DEFAULT 'draft',
    stock_location  ENUM('shop','store','both') NOT NULL DEFAULT 'both',
    started_by      INT           NOT NULL,
    completed_by    INT           NULL,
    started_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    completed_at    TIMESTAMP     NULL,
    notes           TEXT,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (started_by) REFERENCES users(id),
    FOREIGN KEY (completed_by) REFERENCES users(id),
    INDEX idx_branch_status (branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_take_lines (
    id              INT           PRIMARY KEY AUTO_INCREMENT,
    session_id      INT           NOT NULL,
    product_code    VARCHAR(200)  NOT NULL,
    stock_location  ENUM('shop','store') NOT NULL,
    system_quantity FLOAT         NOT NULL DEFAULT 0,
    counted_quantity FLOAT        NOT NULL DEFAULT 0,
    variance        FLOAT         GENERATED ALWAYS AS (counted_quantity - system_quantity) STORED,
    FOREIGN KEY (session_id) REFERENCES stock_take_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_code) REFERENCES products(product_code),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS journal_entry_lines;
DROP TABLE IF EXISTS journal_entries;
DROP TABLE IF EXISTS chart_of_accounts;
CREATE TABLE chart_of_accounts (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id  INT           NOT NULL,
    account_code     VARCHAR(20)   NOT NULL,
    account_name     VARCHAR(200)  NOT NULL,
    account_type     ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    parent_id        INT           NULL,
    is_active        BOOLEAN       DEFAULT TRUE,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id),
    UNIQUE KEY uq_org_account (organization_id, account_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE journal_entries (
    id               BIGINT        PRIMARY KEY AUTO_INCREMENT,
    organization_id  INT           NOT NULL,
    branch_id        INT           NULL,
    entry_number     VARCHAR(50)   NOT NULL,
    entry_date       DATE          NOT NULL,
    reference_type   VARCHAR(50),
    reference_id     BIGINT,
    description      TEXT,
    status           ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
    created_by       INT           NOT NULL,
    posted_at        TIMESTAMP     NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    UNIQUE KEY uq_entry_number (organization_id, entry_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE journal_entry_lines (
    id               BIGINT        PRIMARY KEY AUTO_INCREMENT,
    journal_entry_id BIGINT        NOT NULL,
    account_id       INT           NOT NULL,
    debit            DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit           DECIMAL(14,2) NOT NULL DEFAULT 0,
    line_notes       VARCHAR(255),
    FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS payroll_lines;
DROP TABLE IF EXISTS payroll_runs;
DROP TABLE IF EXISTS pay_periods;
DROP TABLE IF EXISTS employee_documents;
DROP TABLE IF EXISTS employee_attendance;
DROP TABLE IF EXISTS employee_cash_advances;
DROP TABLE IF EXISTS employee_overtime;
DROP TABLE IF EXISTS employee_deductions;
DROP TABLE IF EXISTS payroll_deduction_types;
DROP TABLE IF EXISTS employee_next_of_kin;
DROP TABLE IF EXISTS employee_emergency_contacts;
DROP TABLE IF EXISTS employee_bank_accounts;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS positions;
DROP TABLE IF EXISTS departments;
CREATE TABLE departments (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id  INT           NOT NULL,
    department_code  VARCHAR(45)   NOT NULL,
    department_name  VARCHAR(200)  NOT NULL,
    is_active        BOOLEAN       DEFAULT TRUE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    UNIQUE KEY uq_org_dept (organization_id, department_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE positions (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id  INT           NOT NULL,
    position_code    VARCHAR(45)   NOT NULL,
    position_title   VARCHAR(200)  NOT NULL,
    description      VARCHAR(500)  NULL,
    is_active        BOOLEAN       DEFAULT TRUE,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    UNIQUE KEY uq_org_position (organization_id, position_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees (
    id                    INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id       INT           NOT NULL,
    branch_id             INT           NULL,
    department_id         INT           NULL,
    position_id           INT           NULL,
    user_id               INT           NULL,
    reports_to_employee_id INT          NULL,
    employee_code         VARCHAR(45)   NOT NULL,
    payroll_number        VARCHAR(45)   NULL,
    first_name            VARCHAR(100)  NULL,
    middle_name           VARCHAR(100)  NULL,
    last_name             VARCHAR(100)  NULL,
    full_name             VARCHAR(200)  NOT NULL,
    gender                ENUM('male','female','other','undisclosed') NULL,
    date_of_birth         DATE          NULL,
    nationality           VARCHAR(100)  NULL,
    national_id           VARCHAR(45)   NULL,
    id_document_type      ENUM('national_id','passport') DEFAULT 'national_id',
    marital_status        ENUM('single','married','divorced','widowed','other') NULL,
    personal_email        VARCHAR(255)  NULL,
    email                 VARCHAR(255)  NULL,
    phone                 VARCHAR(45)   NULL,
    alt_phone             VARCHAR(45)   NULL,
    physical_address      VARCHAR(500)  NULL,
    postal_address        VARCHAR(500)  NULL,
    city                  VARCHAR(100)  NULL,
    county                VARCHAR(100)  NULL,
    country               VARCHAR(100)  DEFAULT 'Kenya',
    photo_path            VARCHAR(500)  NULL,
    employment_status     ENUM('active','suspended','terminated','retired') DEFAULT 'active',
    employment_type       ENUM('permanent','contract','casual','intern') DEFAULT 'permanent',
    job_title             VARCHAR(100)  NULL,
    hire_date             DATE          NULL,
    confirmation_date     DATE          NULL,
    probation_end_date    DATE          NULL,
    contract_start_date   DATE          NULL,
    contract_end_date     DATE          NULL,
    notice_period_days    INT           NULL,
    pay_frequency         ENUM('monthly','biweekly','weekly') DEFAULT 'monthly',
    base_salary           DECIMAL(12,2) DEFAULT 0,
    kra_pin               VARCHAR(45)   NULL,
    nssf_number           VARCHAR(45)   NULL,
    sha_number            VARCHAR(45)   NULL,
    housing_levy_number   VARCHAR(45)   NULL,
    is_active             BOOLEAN       DEFAULT TRUE,
    created_at            TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reports_to_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
    UNIQUE KEY uq_org_employee (organization_id, employee_code),
    INDEX idx_emp_branch (branch_id),
    INDEX idx_emp_dept (department_id),
    INDEX idx_emp_position (position_id),
    INDEX idx_emp_reports_to (reports_to_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payroll_deduction_types (
    id                  INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id     INT           NOT NULL,
    deduction_code      VARCHAR(45)   NOT NULL,
    name                VARCHAR(200)  NOT NULL,
    calc_type           ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    default_amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
    default_percentage  DECIMAL(5,2)  NULL,
    is_active           BOOLEAN       DEFAULT TRUE,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    UNIQUE KEY uq_org_deduction_code (organization_id, deduction_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_deductions (
    id                  INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id         INT           NOT NULL,
    deduction_type_id   INT           NULL,
    name                VARCHAR(200)  NOT NULL,
    calc_type           ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    amount              DECIMAL(12,2) NOT NULL DEFAULT 0,
    percentage          DECIMAL(5,2)  NULL,
    start_date          DATE          NULL,
    end_date            DATE          NULL,
    is_active           BOOLEAN       DEFAULT TRUE,
    notes               VARCHAR(500)  NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (deduction_type_id) REFERENCES payroll_deduction_types(id) ON DELETE SET NULL,
    INDEX idx_emp_deduction (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_bank_accounts (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id      INT           NOT NULL,
    bank_name        VARCHAR(200)  NOT NULL,
    bank_branch      VARCHAR(200)  NULL,
    account_number   VARCHAR(45)   NOT NULL,
    account_name     VARCHAR(200)  NOT NULL,
    payment_method   ENUM('bank_transfer','mpesa','cash','cheque') DEFAULT 'bank_transfer',
    is_primary       BOOLEAN       DEFAULT TRUE,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_emp_bank (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_emergency_contacts (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id      INT           NOT NULL,
    full_name        VARCHAR(200)  NOT NULL,
    relationship     VARCHAR(100)  NULL,
    phone            VARCHAR(45)   NOT NULL,
    email            VARCHAR(255)  NULL,
    address          VARCHAR(500)  NULL,
    is_primary       BOOLEAN       DEFAULT FALSE,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_emp_emergency (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_next_of_kin (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id      INT           NOT NULL,
    full_name        VARCHAR(200)  NOT NULL,
    relationship     VARCHAR(100)  NULL,
    national_id      VARCHAR(45)   NULL,
    phone            VARCHAR(45)   NOT NULL,
    address          VARCHAR(500)  NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY uq_employee_nok (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pay_periods (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    organization_id  INT           NOT NULL,
    period_code      VARCHAR(45)   NOT NULL,
    period_start     DATE          NOT NULL,
    period_end       DATE          NOT NULL,
    status           ENUM('open','closed') DEFAULT 'open',
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    UNIQUE KEY uq_org_period (organization_id, period_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_overtime (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id      INT           NOT NULL,
    organization_id  INT           NOT NULL,
    work_date        DATE          NOT NULL,
    hours            DECIMAL(6,2)  NOT NULL DEFAULT 0,
    hourly_rate      DECIMAL(12,2) NULL,
    rate_multiplier  DECIMAL(4,2)  NOT NULL DEFAULT 1.50,
    amount           DECIMAL(12,2) NOT NULL DEFAULT 0,
    status           ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
    pay_period_id    INT           NULL,
    notes            VARCHAR(500)  NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id) ON DELETE SET NULL,
    INDEX idx_ot_emp_date (employee_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_cash_advances (
    id                 INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id        INT           NOT NULL,
    organization_id    INT           NOT NULL,
    advance_date       DATE          NOT NULL,
    amount             DECIMAL(12,2) NOT NULL,
    balance            DECIMAL(12,2) NOT NULL,
    status             ENUM('open','repaid','cancelled') NOT NULL DEFAULT 'open',
    repayment_amount   DECIMAL(12,2) NULL,
    notes              VARCHAR(500)  NULL,
    created_at         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    INDEX idx_adv_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_attendance (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id      INT           NOT NULL,
    organization_id  INT           NOT NULL,
    branch_id        INT           NULL,
    attendance_date  DATE          NOT NULL,
    check_in         TIME          NULL,
    check_out        TIME          NULL,
    status           ENUM('present','absent','late','half_day','leave','holiday') NOT NULL DEFAULT 'present',
    hours_worked     DECIMAL(5,2)  NULL,
    notes            VARCHAR(500)  NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (organization_id) REFERENCES organizations(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    UNIQUE KEY uq_emp_attendance_date (employee_id, attendance_date),
    INDEX idx_att_org_date (organization_id, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_documents (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    employee_id      INT           NOT NULL,
    document_type    ENUM('contract','national_id','passport','kra_pin','offer_letter','certificate','other') NOT NULL DEFAULT 'other',
    title            VARCHAR(200)  NOT NULL,
    file_path        VARCHAR(500)  NOT NULL,
    file_name        VARCHAR(255)  NOT NULL,
    mime_type        VARCHAR(100)  NULL,
    file_size        INT           NULL,
    uploaded_by      INT           NULL,
    notes            VARCHAR(500)  NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_emp_doc (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payroll_runs (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    pay_period_id    INT           NOT NULL,
    run_date         DATE          NOT NULL,
    status           ENUM('draft','processed','paid','void') DEFAULT 'draft',
    processed_by     INT           NULL,
    total_gross      DECIMAL(14,2) DEFAULT 0,
    total_net        DECIMAL(14,2) DEFAULT 0,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pay_period_id) REFERENCES pay_periods(id),
    FOREIGN KEY (processed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payroll_lines (
    id               INT           PRIMARY KEY AUTO_INCREMENT,
    payroll_run_id   INT           NOT NULL,
    employee_id      INT           NOT NULL,
    gross_pay        DECIMAL(12,2) NOT NULL DEFAULT 0,
    nssf             DECIMAL(12,2) NOT NULL DEFAULT 0,
    shif             DECIMAL(12,2) NOT NULL DEFAULT 0,
    housing_levy     DECIMAL(12,2) NOT NULL DEFAULT 0,
    paye             DECIMAL(12,2) NOT NULL DEFAULT 0,
    other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    deductions       DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay          DECIMAL(12,2) NOT NULL DEFAULT 0,
    taxable_income   DECIMAL(12,2) NOT NULL DEFAULT 0,
    employer_nssf    DECIMAL(12,2) NOT NULL DEFAULT 0,
    employer_housing DECIMAL(12,2) NOT NULL DEFAULT 0,
    statutory_meta   JSON          NULL,
    FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- SECTION 13: REPORTING VIEWS
-- ================================================================

DROP VIEW IF EXISTS v_eod_cashier_summary;
CREATE VIEW v_eod_cashier_summary AS
SELECT
    DATE(s.created_at) AS sale_date,
    s.branch_id,
    b.branch_name,
    s.cashier_id,
    u.username AS cashier,
    COUNT(DISTINCT s.id) AS total_transactions,
    SUM(s.order_total) AS gross_sales,
    SUM(s.total_vat) AS total_vat,
    SUM(s.cash) AS cash_collected,
    SUM(s.mpesa_amount) AS mpesa_collected,
    SUM(s.equity_amount) AS equity_collected,
    SUM(s.kcb_amount) AS kcb_collected,
    SUM(s.order_total) - SUM(s.total_vat) AS net_sales,
    tfs.working_amount AS opening_float,
    tfs.float_breakdown AS float_breakdown_json
FROM sales s
JOIN branches b ON s.branch_id = b.id
JOIN users u ON s.cashier_id = u.id
LEFT JOIN till_float_sessions tfs ON s.float_session_id = tfs.id
WHERE s.status = 'completed' AND s.archived = 0
GROUP BY DATE(s.created_at), s.branch_id, s.cashier_id, tfs.id;

DROP VIEW IF EXISTS v_sales_by_product;
CREATE VIEW v_sales_by_product AS
SELECT
    si.product_code,
    p.product_name,
    DATE(s.created_at) AS sale_date,
    s.branch_id,
    s.channel,
    SUM(si.quantity) AS qty_sold,
    si.uom AS sell_uom,
    SUM(si.amount) AS total_revenue,
    SUM(si.product_vat) AS total_vat,
    SUM(si.discount_given) AS total_discount
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code
WHERE s.status = 'completed'
GROUP BY si.product_code, DATE(s.created_at), s.branch_id, s.channel, si.uom;

DROP VIEW IF EXISTS v_sales_by_customer;
CREATE VIEW v_sales_by_customer AS
SELECT
    c.customer_num,
    c.customer_name,
    c.phone_number,
    r.route_name,
    COUNT(DISTINCT s.id) AS total_orders,
    SUM(s.order_total) AS total_purchased,
    COALESCE(SUM(ci.invoice_total),0) AS total_invoiced,
    COALESCE(SUM(ci.amount_paid),0) AS total_paid,
    COALESCE(SUM(ci.balance_due),0) AS total_outstanding,
    c.current_balance AS ar_balance
FROM customers c
LEFT JOIN sales s ON s.customer_num = c.customer_num AND s.status='completed'
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN customer_invoices ci ON ci.customer_num = c.customer_num AND ci.deleted_at IS NULL
WHERE c.deleted_at IS NULL
GROUP BY c.customer_num;

DROP VIEW IF EXISTS v_ar_aging;
CREATE VIEW v_ar_aging AS
SELECT
    ci.customer_num,
    c.customer_name,
    c.phone_number,
    ci.invoice_number,
    ci.invoice_date,
    ci.due_date,
    ci.invoice_total,
    ci.amount_paid,
    ci.balance_due,
    ci.payment_status,
    DATEDIFF(CURRENT_DATE, ci.invoice_date) AS days_outstanding,
    CASE
        WHEN DATEDIFF(CURRENT_DATE, ci.invoice_date) <= 30 THEN '0-30 days'
        WHEN DATEDIFF(CURRENT_DATE, ci.invoice_date) <= 60 THEN '31-60 days'
        WHEN DATEDIFF(CURRENT_DATE, ci.invoice_date) <= 90 THEN '61-90 days'
        ELSE 'Over 90 days'
    END AS aging_bucket
FROM customer_invoices ci
JOIN customers c ON ci.customer_num = c.customer_num
WHERE ci.payment_status IN (0,1) AND ci.deleted_at IS NULL;

DROP VIEW IF EXISTS v_stock_on_hand;
CREATE VIEW v_stock_on_hand AS
SELECT
    cs.branch_id,
    p.product_code,
    p.product_name,
    p.unit_price AS wholesale_price,
    u.full_name AS uom_name,
    u.conversion_factor,
    cs.shop_quantity,
    cs.store_quantity,
    (cs.shop_quantity + cs.store_quantity) AS total_base_units,
    p.reorder_point,
    CASE
        WHEN p.reorder_point > 0
            AND (cs.shop_quantity + cs.store_quantity) <= p.reorder_point THEN 'REORDER'
        ELSE 'OK'
    END AS product_alert,
    rps.max_qty_measure,
    rps.markup_price,
    rps.wholesale_markup_price
FROM current_stock cs
JOIN products p ON cs.product_code = p.product_code
JOIN uoms u ON p.unit_id = u.id
LEFT JOIN retail_package_settings rps ON p.product_code = rps.product_code
WHERE p.deleted_at IS NULL;

DROP VIEW IF EXISTS v_purchases_by_supplier;
CREATE VIEW v_purchases_by_supplier AS
SELECT
    s.id AS supplier_id,
    s.supplier_name,
    l.lpo_no,
    l.created_at AS order_date,
    l.due_date,
    l.total_amount,
    l.lpo_status_code,
    ls.status_name,
    COUNT(t.id) AS line_items,
    SUM(t.ordered_qty) AS total_qty_ordered,
    SUM(t.received_qty) AS total_qty_received
FROM suppliers s
JOIN lpo_mst l ON l.supplier_id = s.id
JOIN lpo_statuses ls ON l.lpo_status_code = ls.status_code
JOIN lpo_txn t ON t.lpo_no = l.lpo_no
GROUP BY s.id, l.lpo_no;

DROP VIEW IF EXISTS v_profit_loss_summary;
CREATE VIEW v_profit_loss_summary AS
SELECT
    DATE(s.created_at) AS period,
    s.branch_id,
    b.branch_name,
    SUM(s.order_total) AS gross_revenue,
    SUM(s.total_vat) AS vat_collected,
    SUM(s.order_total) - SUM(s.total_vat) AS net_revenue,
    COALESCE(cogs.total_cost, 0) AS cogs,
    (SUM(s.order_total) - SUM(s.total_vat)) - COALESCE(cogs.total_cost, 0) AS gross_profit,
    COALESCE(exp.total_expenses, 0) AS total_expenses,
    (SUM(s.order_total) - SUM(s.total_vat)) - COALESCE(cogs.total_cost, 0) - COALESCE(exp.total_expenses, 0) AS net_profit
FROM sales s
JOIN branches b ON s.branch_id = b.id
LEFT JOIN (
    SELECT DATE(sr.created_at) AS cost_date, sr.organization_id,
           SUM(sr.units_received * sr.cost_price) AS total_cost
    FROM stock_receipts sr GROUP BY DATE(sr.created_at), sr.organization_id
) cogs ON DATE(s.created_at) = cogs.cost_date AND s.organization_id = cogs.organization_id
LEFT JOIN (
    SELECT expense_date, branch_id, SUM(expense_amount) AS total_expenses
    FROM expenses WHERE deleted_at IS NULL GROUP BY expense_date, branch_id
) exp ON DATE(s.created_at) = exp.expense_date AND s.branch_id = exp.branch_id
WHERE s.status = 'completed'
GROUP BY DATE(s.created_at), s.branch_id;

DROP VIEW IF EXISTS v_route_loading_summary;
CREATE VIEW v_route_loading_summary AS
SELECT
    DATE(s.created_at) AS loading_date,
    r.route_name,
    r.route_markup_price,
    s.cashier_id,
    u.username AS salesperson,
    COUNT(DISTINCT s.id) AS total_orders,
    COUNT(si.id) AS total_items,
    SUM(si.quantity) AS total_qty,
    SUM(si.amount) AS total_value,
    SUM(s.order_total) AS grand_total,
    SUM(CASE WHEN s.status='completed' THEN s.order_total ELSE 0 END) AS delivered_value,
    SUM(CASE WHEN s.is_credit_sale=0 THEN s.order_total ELSE 0 END) AS cash_collected,
    SUM(CASE WHEN s.is_credit_sale=1 THEN s.order_total ELSE 0 END) AS credit_outstanding
FROM sales s
JOIN routes r ON s.route_id = r.id
JOIN users u ON s.cashier_id = u.id
JOIN sale_items si ON si.sale_id = s.id
WHERE s.channel = 'mobile'
GROUP BY DATE(s.created_at), s.route_id, s.cashier_id;

DROP VIEW IF EXISTS v_stock_chain;
CREATE VIEW v_stock_chain AS
SELECT
    it.branch_id,
    it.product_code,
    p.product_name,
    MIN(CASE WHEN it.transaction_type='PURCHASE' THEN it.created_at END) AS first_received_at,
    MIN(CASE WHEN it.transaction_type IN ('POS_SALE','MOBILE_SALE','BACKEND_SALE') THEN it.created_at END) AS first_sold_at,
    MAX(it.created_at) AS last_movement_at,
    SUM(CASE WHEN it.transaction_type='PURCHASE' AND it.quantity_change>0 THEN it.quantity_change ELSE 0 END) AS total_received,
    SUM(CASE WHEN it.transaction_type IN ('POS_SALE','MOBILE_SALE','BACKEND_SALE') THEN ABS(it.quantity_change) ELSE 0 END) AS total_sold,
    cs.shop_quantity AS current_shop_stock,
    cs.store_quantity AS current_store_stock
FROM inventory_transactions it
JOIN products p ON it.product_code = p.product_code
LEFT JOIN current_stock cs ON cs.product_code = it.product_code AND cs.branch_id = it.branch_id
GROUP BY it.branch_id, it.product_code, p.product_name, cs.shop_quantity, cs.store_quantity;

DROP VIEW IF EXISTS v_sales_by_user;
CREATE VIEW v_sales_by_user AS
SELECT
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    s.cashier_id,
    u.full_name AS salesperson,
    s.channel,
    COUNT(DISTINCT s.id) AS order_count,
    SUM(s.order_total) AS gross_sales,
    SUM(s.amount_paid) AS amount_collected
FROM sales s
JOIN users u ON s.cashier_id = u.id
WHERE s.status = 'completed'
GROUP BY DATE(s.completed_at), s.branch_id, s.cashier_id, s.channel;

DROP VIEW IF EXISTS v_stock_valuation;
CREATE VIEW v_stock_valuation AS
SELECT
    cs.branch_id,
    p.product_code,
    p.product_name,
    cs.shop_quantity,
    cs.store_quantity,
    (cs.shop_quantity + cs.store_quantity) AS total_qty,
    p.last_cost_price,
    p.unit_price,
    (cs.shop_quantity + cs.store_quantity) * COALESCE(p.last_cost_price, 0) AS cost_value,
    (cs.shop_quantity + cs.store_quantity) * p.unit_price AS retail_value
FROM current_stock cs
JOIN products p ON cs.product_code = p.product_code
WHERE p.deleted_at IS NULL;

DROP VIEW IF EXISTS v_daily_sales;
CREATE VIEW v_daily_sales AS
SELECT
    DATE(s.completed_at) AS sale_day,
    s.branch_id,
    b.branch_name,
    s.channel,
    COUNT(*) AS orders,
    SUM(s.order_total) AS gross,
    SUM(s.total_vat) AS vat,
    SUM(s.order_total - s.total_vat) AS net
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE s.status = 'completed' AND s.archived = 0
GROUP BY DATE(s.completed_at), s.branch_id, s.channel;

DROP VIEW IF EXISTS v_sales_by_channel;
CREATE VIEW v_sales_by_channel AS
SELECT
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.payment_status,
    COUNT(*) AS order_count,
    SUM(s.order_total) AS gross_sales,
    SUM(s.amount_paid) AS collected,
    SUM(s.total_vat) AS total_vat,
    SUM(s.order_total - s.total_vat) AS net_sales,
    SUM(CASE WHEN s.is_credit_sale = 1 THEN s.order_total ELSE 0 END) AS credit_sales
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE s.status = 'completed' AND s.archived = 0
GROUP BY DATE(s.completed_at), s.branch_id, s.channel, s.payment_status;

DROP VIEW IF EXISTS v_payment_collection;
CREATE VIEW v_payment_collection AS
SELECT
    DATE(sp.paid_at) AS payment_date,
    s.branch_id,
    s.channel,
    pm.method_code,
    pm.method_name,
    COUNT(*) AS payment_count,
    SUM(sp.amount) AS total_collected
FROM sale_payments sp
JOIN sales s ON sp.sale_id = s.id
JOIN payment_methods pm ON sp.payment_method_id = pm.id
WHERE s.status = 'completed'
GROUP BY DATE(sp.paid_at), s.branch_id, s.channel, pm.method_code, pm.method_name;

DROP VIEW IF EXISTS v_low_stock;
CREATE VIEW v_low_stock AS
SELECT * FROM v_stock_on_hand WHERE product_alert = 'REORDER';

DROP VIEW IF EXISTS v_open_lpo_lines;
CREATE VIEW v_open_lpo_lines AS
SELECT
    l.lpo_no,
    l.supplier_id,
    sup.supplier_name,
    l.lpo_status_code,
    ls.status_name,
    l.due_date,
    t.product_code,
    p.product_name,
    t.ordered_qty,
    COALESCE(t.received_qty, 0) AS received_qty,
    (t.ordered_qty - COALESCE(t.received_qty, 0)) AS pending_qty,
    t.cost_price,
    t.uom
FROM lpo_txn t
JOIN lpo_mst l ON t.lpo_no = l.lpo_no
JOIN suppliers sup ON l.supplier_id = sup.id
JOIN lpo_statuses ls ON l.lpo_status_code = ls.status_code
JOIN products p ON t.product_code = p.product_code
WHERE l.deleted_at IS NULL
  AND l.lpo_status_code IN (2, 3)
  AND (t.ordered_qty - COALESCE(t.received_qty, 0)) > 0.0001;

DROP VIEW IF EXISTS v_expenses_summary;
CREATE VIEW v_expenses_summary AS
SELECT
    e.expense_date,
    e.branch_id,
    b.branch_name,
    eg.id AS expense_group_id,
    eg.group_name,
    COUNT(*) AS expense_count,
    SUM(e.expense_amount) AS total_amount
FROM expenses e
JOIN branches b ON e.branch_id = b.id
JOIN expense_groups eg ON e.expense_group_id = eg.id
WHERE e.deleted_at IS NULL
GROUP BY e.expense_date, e.branch_id, eg.id, eg.group_name;

DROP VIEW IF EXISTS v_damages_summary;
CREATE VIEW v_damages_summary AS
SELECT
    DATE(d.created_at) AS damage_date,
    d.branch_id,
    d.product_code,
    p.product_name,
    d.stock_location,
    d.package_type,
    SUM(d.quantity) AS total_qty,
    COUNT(*) AS incident_count
FROM damages d
JOIN products p ON d.product_code = p.product_code
GROUP BY DATE(d.created_at), d.branch_id, d.product_code, p.product_name, d.stock_location, d.package_type;

DROP VIEW IF EXISTS v_supplier_returns_detail;
CREATE VIEW v_supplier_returns_detail AS
SELECT
    DATE(sr.created_at) AS return_date,
    sr.branch_id,
    sr.supplier_id,
    s.supplier_name,
    sr.product_code,
    p.product_name,
    sr.quantity,
    sr.stock_location,
    sr.reason,
    u.username AS returned_by
FROM supplier_returns sr
JOIN suppliers s ON sr.supplier_id = s.id
JOIN products p ON sr.product_code = p.product_code
JOIN users u ON sr.returned_by = u.id;

DROP VIEW IF EXISTS v_stock_receipts_detail;
CREATE VIEW v_stock_receipts_detail AS
SELECT
    DATE(sr.created_at) AS receipt_date,
    sr.branch_id,
    sr.organization_id,
    sr.product_code,
    p.product_name,
    sr.units_received,
    sr.stock_location,
    sr.cost_price,
    (sr.units_received * COALESCE(sr.cost_price, 0)) AS line_cost,
    sr.invoice_number,
    u.username AS received_by
FROM stock_receipts sr
JOIN products p ON sr.product_code = p.product_code
JOIN users u ON sr.received_by = u.id;

DROP VIEW IF EXISTS v_credit_outstanding;
CREATE VIEW v_credit_outstanding AS
SELECT
    s.id AS sale_id,
    s.order_num,
    s.branch_id,
    s.channel,
    s.customer_num,
    COALESCE(c.customer_name, s.customer_name_override) AS customer_name,
    s.status,
    s.payment_status,
    s.order_total,
    s.amount_paid,
    (s.order_total - s.amount_paid) AS balance_due,
    s.created_at,
    s.completed_at
FROM sales s
LEFT JOIN customers c ON s.customer_num = c.customer_num
WHERE s.is_credit_sale = 1
  AND s.payment_status IN ('unpaid', 'partial')
  AND s.status NOT IN ('cancelled')
  AND s.archived = 0;

DROP VIEW IF EXISTS v_kra_receipts;
CREATE VIEW v_kra_receipts AS
SELECT
    DATE(kr.created_at) AS receipt_date,
    s.branch_id,
    s.channel,
    kr.status,
    COUNT(*) AS receipt_count,
    SUM(s.order_total) AS order_total
FROM kra_responses kr
JOIN sales s ON kr.sale_id = s.id
GROUP BY DATE(kr.created_at), s.branch_id, s.channel, kr.status;

DROP VIEW IF EXISTS v_stock_reservations_active;
CREATE VIEW v_stock_reservations_active AS
SELECT
    sr.branch_id,
    sr.product_code,
    p.product_name,
    sr.stock_location,
    SUM(sr.quantity) AS reserved_qty,
    COUNT(*) AS reservation_count
FROM stock_reservations sr
JOIN products p ON sr.product_code = p.product_code
WHERE sr.released_at IS NULL
GROUP BY sr.branch_id, sr.product_code, p.product_name, sr.stock_location;

DROP VIEW IF EXISTS v_sales_pipeline;
CREATE VIEW v_sales_pipeline AS
SELECT
    DATE(s.created_at) AS order_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    s.status,
    s.payment_status,
    COUNT(*) AS order_count,
    SUM(s.order_total) AS pipeline_value,
    SUM(s.amount_paid) AS collected_so_far
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE s.status NOT IN ('completed', 'cancelled')
  AND s.archived = 0
GROUP BY DATE(s.created_at), s.branch_id, s.channel, s.status, s.payment_status;

DROP VIEW IF EXISTS v_vat_collected;
CREATE VIEW v_vat_collected AS
SELECT
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    b.branch_name,
    s.channel,
    SUM(s.total_vat) AS vat_collected,
    SUM(s.order_total) AS gross_sales,
    COUNT(*) AS orders
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE s.status = 'completed' AND s.archived = 0
GROUP BY DATE(s.completed_at), s.branch_id, s.channel;

DROP VIEW IF EXISTS v_category_sales;
CREATE VIEW v_category_sales AS
SELECT
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    c.id AS category_id,
    c.category_name,
    sc.id AS sub_category_id,
    sc.subcategory_name,
    SUM(si.quantity) AS qty_sold,
    SUM(si.amount) AS revenue,
    SUM(si.product_vat) AS vat,
    SUM(si.discount_given) AS discounts
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
JOIN products p ON si.product_code = p.product_code
JOIN sub_categories sc ON p.subcategory_id = sc.id
JOIN categories c ON sc.category_id = c.id
WHERE s.status = 'completed'
GROUP BY DATE(s.completed_at), s.branch_id, c.id, sc.id;

DROP VIEW IF EXISTS v_till_session_summary;
CREATE VIEW v_till_session_summary AS
SELECT
    tfs.id AS session_id,
    tfs.session_date,
    tfs.branch_id,
    tfs.till_id,
    t.till_number,
    tfs.cashier_id,
    u.username AS cashier,
    tfs.status,
    tfs.working_amount AS opening_float,
    tfs.closing_amount,
    tfs.expected_amount,
    tfs.cash_sales,
    COALESCE(s.txn_count, 0) AS completed_sales,
    COALESCE(s.gross, 0) AS gross_sales
FROM till_float_sessions tfs
JOIN tills t ON tfs.till_id = t.id
JOIN users u ON tfs.cashier_id = u.id
LEFT JOIN (
    SELECT float_session_id, COUNT(*) AS txn_count, SUM(order_total) AS gross
    FROM sales WHERE status = 'completed' GROUP BY float_session_id
) s ON s.float_session_id = tfs.id;

DROP VIEW IF EXISTS v_payroll_summary;
CREATE VIEW v_payroll_summary AS
SELECT
    pr.id AS payroll_run_id,
    pp.organization_id,
    pp.period_code,
    pp.period_start,
    pp.period_end,
    pr.run_date,
    pr.status,
    pr.total_gross,
    pr.total_net,
    COUNT(pl.id) AS employee_count
FROM payroll_runs pr
JOIN pay_periods pp ON pr.pay_period_id = pp.id
LEFT JOIN payroll_lines pl ON pl.payroll_run_id = pr.id
GROUP BY pr.id;

DROP VIEW IF EXISTS v_journal_register;
CREATE VIEW v_journal_register AS
SELECT
    je.id AS journal_entry_id,
    je.organization_id,
    je.branch_id,
    je.entry_number,
    je.entry_date,
    je.reference_type,
    je.reference_id,
    je.status,
    je.description,
    SUM(jel.debit) AS total_debit,
    SUM(jel.credit) AS total_credit,
    u.username AS created_by
FROM journal_entries je
JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
JOIN users u ON je.created_by = u.id
GROUP BY je.id;

DROP VIEW IF EXISTS v_top_debtors;
CREATE VIEW v_top_debtors AS
SELECT
    c.customer_num,
    c.customer_name,
    c.phone_number,
    r.route_name,
    c.current_balance,
    COUNT(DISTINCT ci.id) AS open_invoices,
    COALESCE(SUM(ci.balance_due), 0) AS invoice_balance
FROM customers c
LEFT JOIN routes r ON c.route_id = r.id
LEFT JOIN customer_invoices ci ON ci.customer_num = c.customer_num
    AND ci.payment_status IN (0, 1) AND ci.deleted_at IS NULL
WHERE c.deleted_at IS NULL AND (c.current_balance > 0 OR ci.id IS NOT NULL)
GROUP BY c.customer_num
HAVING c.current_balance > 0 OR COALESCE(SUM(ci.balance_due), 0) > 0;

DROP VIEW IF EXISTS v_discount_summary;
CREATE VIEW v_discount_summary AS
SELECT
    DATE(s.completed_at) AS sale_date,
    s.branch_id,
    s.channel,
    COUNT(DISTINCT s.id) AS orders_with_discount,
    SUM(si.discount_given) AS total_discount,
    SUM(si.amount) AS net_line_sales
FROM sale_items si
JOIN sales s ON si.sale_id = s.id
WHERE s.status = 'completed' AND si.discount_given > 0
GROUP BY DATE(s.completed_at), s.branch_id, s.channel;

DROP VIEW IF EXISTS v_stock_transfers;
CREATE VIEW v_stock_transfers AS
SELECT
    DATE(smh.created_at) AS transfer_date,
    smh.branch_id,
    smh.product_code,
    p.product_name,
    smh.from_location,
    smh.to_location,
    SUM(smh.quantity_moved) AS total_moved,
    COUNT(*) AS transfer_count
FROM stock_movement_history smh
JOIN products p ON smh.product_code = p.product_code
GROUP BY DATE(smh.created_at), smh.branch_id, smh.product_code, smh.from_location, smh.to_location;

DROP VIEW IF EXISTS v_invoice_payment_history;
CREATE VIEW v_invoice_payment_history AS
SELECT
    cip.id AS payment_id,
    cip.customer_num,
    c.customer_name,
    ci.invoice_number,
    cip.date_paid,
    cip.amount_paid,
    pm.method_name,
    u.username AS received_by,
    cip.reference_number
FROM customer_invoice_payments cip
JOIN customers c ON cip.customer_num = c.customer_num
JOIN customer_invoices ci ON cip.customer_invoice_id = ci.id
JOIN payment_methods pm ON cip.payment_method_id = pm.id
JOIN users u ON cip.received_by = u.id;

-- ================================================================
-- SECTION 14: SEED DATA
-- ================================================================

INSERT INTO lpo_statuses (status_code, status_name) VALUES
(0,'Pending – Awaiting LPO To be Checked'),
(1,'Pending – Awaiting Approval'),
(2,'Awaiting to be Sent to Supplier'),
(3,'Awaiting Items to be Received'),
(4,'Awaiting Last Items to be Received'),
(5,'Items Fully Received'),
(6,'LPO Cleared (Payment Made)'),
(7,'Cancelled – Items Returned to Supplier');

INSERT INTO payment_methods (method_name, method_code, requires_reference) VALUES
('Cash',        'CASH',   FALSE),
('M-Pesa',      'MPESA',  TRUE),
('Equity Bank', 'EQUITY', TRUE),
('KCB Bank',    'KCB',    TRUE),
('Credit',      'CREDIT', FALSE),
('Airtel Money','AIRTEL', TRUE),
('Cheque',      'CHEQUE', TRUE);

INSERT INTO vats (vat_code, vat_name, vat_percentage) VALUES
('V','Standard Rated',16.00),('Z','Zero Rated',0.00),('E','VAT Exempt',0.00);

INSERT INTO roles (role_name, scope) VALUES
('Organisation Admin','org'),('Branch Manager','branch'),('Cashier','branch'),
('Stock Clerk','branch'),('Route Salesperson','branch'),('Viewer','branch'),
('Distribution Manager','branch');

INSERT INTO expense_groups (group_name) VALUES
('Utilities'),('Rent'),('Salaries'),('Maintenance'),
('Marketing'),('Transport'),('Stationery'),('Other');

INSERT INTO system_settings (allow_below_stock, stock_alert_mode) VALUES (0, 'per_product');

SET FOREIGN_KEY_CHECKS = 1;

-- END SCHEMA v3.1 — Tables: 56 | Triggers: 2 | Views: 30
