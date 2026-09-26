CREATE TABLE IF NOT EXISTS mix_match_designs (
    id VARCHAR(80) PRIMARY KEY,
    user_id INT NOT NULL DEFAULT 0,
    user_email VARCHAR(190) NOT NULL,
    design_name VARCHAR(190) NOT NULL,
    room_type VARCHAR(80) NOT NULL,
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at VARCHAR(50) NOT NULL DEFAULT '',
    updated_at VARCHAR(50) NOT NULL DEFAULT '',
    INDEX user_email_index (user_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mix_match_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    design_id VARCHAR(80) NOT NULL,
    product_id VARCHAR(80) NOT NULL,
    selected_color VARCHAR(120) NOT NULL DEFAULT '',
    selected_material VARCHAR(120) NOT NULL DEFAULT '',
    selected_size VARCHAR(120) NOT NULL DEFAULT '',
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    position_x DECIMAL(6,2) NOT NULL DEFAULT 12,
    position_y DECIMAL(6,2) NOT NULL DEFAULT 12,
    rotation DECIMAL(6,2) NOT NULL DEFAULT 0,
    scale_value DECIMAL(6,2) NOT NULL DEFAULT 1,
    layer_order INT NOT NULL DEFAULT 1,
    INDEX design_id_index (design_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
