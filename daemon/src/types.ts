export type ServiceCategory = 'database' | 'file';

export type ApiConfig = {
  name: string;
  baseUrl: string;
  category: ServiceCategory;
  type: string; // The specific service type (e.g., 'sqlite', 'local_file')
};
