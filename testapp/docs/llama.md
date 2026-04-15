# Добавление репозитория NVIDIA
curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | sudo gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
curl -s -L https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list | \
  sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' | \
  sudo tee /etc/apt/sources.list.d/nvidia-container-toolkit.list

# Установка пакета
sudo apt-get update
sudo apt-get install -y nvidia-container-toolkit

# Настройка Docker для работы с NVIDIA
sudo nvidia-ctk runtime configure --runtime=docker
sudo systemctl restart docker

# загрузка llama
docker pull ghcr.io/ggml-org/llama.cpp:full-cuda